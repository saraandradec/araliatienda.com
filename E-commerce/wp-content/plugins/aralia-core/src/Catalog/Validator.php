<?php
/**
 * Validador del CSV de inventario (T043, contracts/inventory-csv.md).
 *
 * @package Aralia
 */

namespace Aralia\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Sin dependencias de WordPress: recibe el texto del CSV y, opcionalmente, un mapa SKU => precio actual para detectar variaciones > 20 %.
 * Errores = bloquean la importación completa. Avisos = se muestran pero no bloquean. Incompletas = se importan como borrador.
 */
final class Validator {

	public const COLUMNS = array(
		'sku',
		'parent_sku',
		'nombre',
		'categoria',
		'subcategoria',
		'marca',
		'depende_tono',
		'tono',
		'subtono',
		'color',
		'look',
		'secciones',
		'paso_full_face',
		'combina_con',
		'costo',
		'precio',
		'stock',
		'foto',
		'foto_uso',
		'swatch',
		'registro_sanitario',
		'descripcion_corta',
		'como_se_usa',
		'ingredientes',
	);

	public const MAX_ROWS      = 2000;
	public const MAX_CSV_BYTES = 2097152;

	/**
	 * Valida el CSV.
	 *
	 * @param string             $csv           Contenido (UTF-8).
	 * @param array<string,int>  $current_prices SKU => precio actual (para el aviso de cambios > 20 %).
	 * @param array<string,string>|null $tones   Familias de tono vigentes (nombre => subtono); por defecto las iniciales.
	 * @return array{rows:array<int,array<string,string>>,errors:array<int,string[]>,warnings:array<int,string[]>,incomplete:array<int,string[]>,price_changes:array<int,array{sku:string,from:int,to:int}>,fatal:string[]}
	 */
	public static function validate( string $csv, array $current_prices = array(), ?array $tones = null ): array {
		$tones  = $tones ?? Taxonomies::TONES;
		$result = array(
			'rows'          => array(),
			'errors'        => array(),
			'warnings'      => array(),
			'incomplete'    => array(),
			'price_changes' => array(),
			'fatal'         => array(),
		);
		if ( strlen( $csv ) > self::MAX_CSV_BYTES ) {
			$result['fatal'][] = 'El CSV supera 2 MB.';
			return $result;
		}
		if ( ! mb_check_encoding( $csv, 'UTF-8' ) ) {
			$result['fatal'][] = 'El archivo no está en UTF-8.';
			return $result;
		}
		$csv     = preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) ?? $csv;
		$records = self::parse( $csv );
		if ( ! $records ) {
			$result['fatal'][] = 'El CSV está vacío.';
			return $result;
		}
		$header = array_map( 'trim', array_shift( $records ) );
		if ( self::COLUMNS !== $header ) {
			$missing           = array_diff( self::COLUMNS, $header );
			$extra             = array_diff( $header, self::COLUMNS );
			$result['fatal'][] = 'Encabezados incorrectos. Faltan: ' . ( $missing ? implode( ', ', $missing ) : 'ninguno' ) . '. Sobran o están fuera de orden: ' . ( $extra ? implode( ', ', $extra ) : 'revisa el orden' ) . '.';
			return $result;
		}
		if ( count( $records ) > self::MAX_ROWS ) {
			$result['fatal'][] = 'El CSV supera ' . self::MAX_ROWS . ' filas.';
			return $result;
		}

		$skus = array();
		foreach ( $records as $i => $cells ) {
			$line = $i + 2; // Fila 1 = encabezados.
			if ( 1 === count( $cells ) && '' === trim( (string) $cells[0] ) ) {
				continue; // Línea en blanco.
			}
			if ( count( $cells ) !== count( self::COLUMNS ) ) {
				$result['errors'][ $line ][] = 'Número de columnas incorrecto (' . count( $cells ) . ' en vez de ' . count( self::COLUMNS ) . ').';
				continue;
			}
			$row                     = array_combine( self::COLUMNS, array_map( 'trim', $cells ) );
			$result['rows'][ $line ] = $row;
			self::validate_row( $row, $line, $result, $tones );
			if ( '' !== $row['sku'] ) {
				if ( isset( $skus[ $row['sku'] ] ) ) {
					$result['errors'][ $line ][] = 'SKU repetido (también en la fila ' . $skus[ $row['sku'] ] . ').';
				}
				$skus[ $row['sku'] ] = $line;
			}
		}
		// Referencias entre filas: parent_sku y combina_con deben existir en el CSV o en el catálogo actual.
		foreach ( $result['rows'] as $line => $row ) {
			if ( '' !== $row['parent_sku'] ) {
				$parent_line = $skus[ $row['parent_sku'] ] ?? 0;
				$parent      = $parent_line ? ( $result['rows'][ $parent_line ] ?? null ) : null;
				if ( ! $parent ) {
					$result['errors'][ $line ][] = 'parent_sku «' . $row['parent_sku'] . '» debe venir en el mismo CSV (en la fila del producto padre).';
				} elseif ( '' !== $parent['parent_sku'] || 'si' !== $parent['depende_tono'] ) {
					$result['errors'][ $line ][] = 'El padre «' . $row['parent_sku'] . '» debe ser un producto con depende_tono = si y sin parent_sku.';
				}
				if ( 'si' !== $row['depende_tono'] ) {
					$result['errors'][ $line ][] = 'Una variación por tono debe llevar depende_tono = si.';
				}
			}
			foreach ( '' === $row['combina_con'] ? array() : explode( '|', $row['combina_con'] ) as $ref ) {
				$ref = trim( $ref );
				if ( $ref === $row['sku'] ) {
					$result['errors'][ $line ][] = 'combina_con no puede apuntar al mismo producto.';
				} elseif ( isset( $skus[ $ref ] ) && '' !== ( $result['rows'][ $skus[ $ref ] ]['parent_sku'] ?? '' ) ) {
					$result['errors'][ $line ][] = 'combina_con «' . $ref . '» es una variación: apunta al producto padre.';
				} elseif ( ! isset( $skus[ $ref ] ) && ! isset( $current_prices[ $ref ] ) ) {
					$result['errors'][ $line ][] = 'combina_con «' . $ref . '» no existe.';
				}
			}
			$price = (int) $row['precio'];
			if ( $price > 0 && isset( $current_prices[ $row['sku'] ] ) && $current_prices[ $row['sku'] ] > 0 ) {
				$from = $current_prices[ $row['sku'] ];
				if ( abs( $price - $from ) / $from > 0.2 ) {
					$result['price_changes'][ $line ] = array(
						'sku'  => $row['sku'],
						'from' => $from,
						'to'   => $price,
					);
				}
			}
		}
		ksort( $result['errors'] );
		return $result;
	}

	/**
	 * Lee el CSV respetando comillas y saltos de línea dentro de una celda.
	 *
	 * @param string $csv Contenido.
	 * @return array<int,array<int,string>>
	 */
	public static function parse( string $csv ): array {
		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $stream ) {
			return array();
		}
		fwrite( $stream, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $stream );
		$rows  = array();
		$cells = fgetcsv( $stream, 0, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		while ( false !== $cells ) {
			$rows[] = array_map( 'strval', $cells );
			$cells  = fgetcsv( $stream, 0, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $rows;
	}

	/**
	 * Una celda que empieza por = + - @ (o tabulador / retorno) es una fórmula en una hoja de cálculo; solo se aceptan números válidos.
	 *
	 * @param string $cell Celda.
	 * @return bool
	 */
	public static function is_formula( string $cell ): bool {
		if ( '' === $cell || ! in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return false;
		}
		return ! (bool) preg_match( '/^[+-]?\d+([.,]\d+)?$/', $cell );
	}

	/**
	 * Valida una fila.
	 *
	 * @param array<string,string> $r      Fila.
	 * @param int                  $line   Línea.
	 * @param array<string,mixed>  $result Resultado (por referencia).
	 * @param array<string,string> $tones  Familias de tono vigentes.
	 * @return void
	 */
	private static function validate_row( array $r, int $line, array &$result, array $tones ): void {
		$err  = array();
		$warn = array();
		$inc  = array();
		foreach ( $r as $col => $cell ) {
			if ( self::is_formula( $cell ) ) {
				$err[] = "La celda «{$col}» empieza por un carácter de fórmula (= + - @) y se rechaza.";
			}
		}
		$is_variation = '' !== $r['parent_sku'];
		// El padre de un producto por tono no lleva tono, precio, stock ni costo propios: los llevan sus variaciones (contrato, fila padre).
		$var_parent = ! $is_variation && 'si' === $r['depende_tono'];
		if ( ! preg_match( '/^[A-Z0-9-]{3,32}$/', $r['sku'] ) ) {
			$err[] = 'sku inválido (A-Z, 0-9 y guion, 3 a 32).';
		}
		$len = mb_strlen( $r['nombre'] );
		if ( 3 > $len || 120 < $len ) {
			$err[] = 'nombre debe tener entre 3 y 120 caracteres.';
		}
		if ( ! in_array( $r['categoria'], Taxonomies::CATEGORIES, true ) ) {
			$err[] = 'categoria fuera de la lista (' . implode( ', ', Taxonomies::CATEGORIES ) . ').';
		}
		foreach ( array( 'subcategoria', 'marca' ) as $col ) {
			if ( '' === $r[ $col ] ) {
				$err[] = "$col es obligatoria.";
			}
		}
		if ( ! in_array( $r['depende_tono'], array( 'si', 'no' ), true ) ) {
			$err[] = 'depende_tono debe ser si o no.';
		}
		if ( 'si' === $r['depende_tono'] ) {
			// FR-019: el tono se pide solo en Piel y lápices de Cejas; nunca en Labios, Ojos ni Pestañas.
			if ( ! in_array( $r['categoria'], array( 'Piel', 'Cejas' ), true ) ) {
				$err[] = 'FR-019: el tono solo aplica a Piel y Cejas, no a ' . $r['categoria'] . '.';
			}
			if ( $is_variation ) {
				if ( ! array_key_exists( $r['tono'], $tones ) ) {
					$err[] = 'tono fuera de la lista vigente (' . implode( ', ', array_keys( $tones ) ) . ').';
				}
				if ( ! in_array( $r['subtono'], Taxonomies::SUBTONES, true ) ) {
					$err[] = 'subtono debe ser frío, neutro o cálido.';
				}
			}
		} elseif ( 'no' === $r['depende_tono'] && '' !== $r['paso_full_face'] && ( Taxonomies::STEPS[ $r['paso_full_face'] ][1] ?? false ) ) {
			$err[] = 'El paso ' . $r['paso_full_face'] . ' exige tono: marca depende_tono = si.';
		}
		$err = array_merge( $err, self::list_errors( 'color', $r['color'], Taxonomies::COLORS, true ), self::list_errors( 'look', $r['look'], Taxonomies::LOOKS, true ) );
		$err = array_merge( $err, self::list_errors( 'secciones', $r['secciones'], array_keys( Taxonomies::SECTIONS ), false ) );
		if ( '' !== $r['paso_full_face'] && ! array_key_exists( $r['paso_full_face'], Taxonomies::STEPS ) ) {
			$err[] = 'paso_full_face fuera de la lista.';
		}
		$refs = '' === $r['combina_con'] ? array() : array_map( 'trim', explode( '|', $r['combina_con'] ) );
		if ( ! $is_variation && 2 !== count( array_unique( $refs ) ) ) {
			$err[] = 'combina_con debe tener exactamente 2 SKU distintos separados por |.';
		}
		if ( ! ( $var_parent && '' === $r['costo'] ) && ! ctype_digit( $r['costo'] ) ) {
			$err[] = 'costo debe ser un entero COP >= 0.';
		}
		// El padre de un producto por tono no lleva precio ni stock (los llevan sus variaciones).
		if ( ! ( $var_parent && '' === $r['precio'] ) ) {
			if ( ! ctype_digit( $r['precio'] ) || (int) $r['precio'] <= 0 ) {
				$err[] = 'precio debe ser un entero COP > 0.';
			} elseif ( 0 !== (int) $r['precio'] % 500 ) {
				$warn[] = 'precio no es múltiplo de 500.';
			}
		}
		if ( ! ( $var_parent && '' === $r['stock'] ) ) {
			if ( ! ctype_digit( $r['stock'] ) ) {
				$err[] = 'stock debe ser un entero >= 0.';
			} elseif ( 0 === (int) $r['stock'] && ! $var_parent ) {
				$inc[] = 'sin_stock';
			}
		}
		if ( mb_strlen( $r['registro_sanitario'] ) > 40 ) {
			$err[] = 'registro_sanitario supera 40 caracteres.';
		}
		foreach ( array( 'foto', 'foto_uso', 'swatch' ) as $col ) {
			if ( '' !== $r[ $col ] && ! self::is_safe_filename( $r[ $col ] ) ) {
				$err[] = "$col debe ser un nombre de archivo sin rutas.";
			}
		}
		if ( '' === $r['foto'] && ! $is_variation ) {
			$inc[] = 'foto';
		}
		if ( '' === $r['swatch'] && ( $is_variation || ( ! $var_parent && '' !== $r['color'] ) ) ) {
			$inc[] = 'swatch';
		}
		if ( $err ) {
			$result['errors'][ $line ] = $err;
		}
		if ( $warn ) {
			$result['warnings'][ $line ] = $warn;
		}
		if ( $inc ) {
			$result['incomplete'][ $line ] = $inc;
		}
	}

	/**
	 * Un nombre de archivo simple: sin separadores, sin `..`, sin bytes nulos, extensión de imagen.
	 *
	 * @param string $name Nombre.
	 * @return bool
	 */
	public static function is_safe_filename( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._ -]{0,119}\.(jpe?g|png|webp)\z/i', $name ) && false === strpos( $name, '..' );
	}

	/**
	 * Errores de una lista separada por `|`.
	 *
	 * @param string   $col      Columna.
	 * @param string   $value    Valor.
	 * @param string[] $allowed  Permitidos.
	 * @param bool     $required Obligatoria.
	 * @return string[]
	 */
	private static function list_errors( string $col, string $value, array $allowed, bool $required ): array {
		if ( '' === $value ) {
			return $required ? array( "$col es obligatoria." ) : array();
		}
		$bad = array_diff( array_map( 'trim', explode( '|', $value ) ), $allowed );
		return $bad ? array( "$col tiene valores fuera de la lista: " . implode( ', ', $bad ) . '.' ) : array();
	}
}
