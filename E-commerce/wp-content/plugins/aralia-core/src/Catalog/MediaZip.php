<?php
/**
 * Extracción segura del ZIP de fotos (T045, L-06).
 *
 * @package Aralia
 */

namespace Aralia\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Rechaza el ZIP completo ante cualquier irregularidad: rutas (relativas, absolutas, con `..` o `\`), directorios, más de 200 archivos,
 * más de 100 MB descomprimidos (se vigila lo declarado Y lo realmente copiado: bomba de compresión) o un tipo real que no sea imagen.
 * Extrae a una carpeta nueva y vacía FUERA de `uploads/`.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions -- Extracción en streaming a una carpeta temporal propia; WP_Filesystem no permite medir el volumen real copiado.
final class MediaZip {

	public const MAX_FILES  = 200;
	public const MAX_BYTES  = 104857600;
	public const MAX_FILE   = 10485760;
	public const MAX_SIDE   = 8000;
	public const MAX_PIXELS = 40000000;

	/**
	 * Tipos reales permitidos.
	 *
	 * @var string[]
	 */
	public const MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Extrae.
	 *
	 * @param string $zip_path Ruta del ZIP.
	 * @param string $dest     Carpeta de destino (se crea; debe estar fuera de uploads).
	 * @return array{files:array<string,string>,errors:string[]}
	 */
	public static function extract( string $zip_path, string $dest ): array {
		$out = array(
			'files'  => array(),
			'errors' => array(),
		);
		if ( ! class_exists( '\ZipArchive' ) ) {
			$out['errors'][] = 'El servidor no tiene soporte para ZIP.';
			return $out;
		}
		$uploads = wp_get_upload_dir();
		$base    = (string) realpath( (string) $uploads['basedir'] );
		$parent  = (string) realpath( dirname( $dest ) );
		$public  = array_filter( array( $base, (string) realpath( ABSPATH ), (string) realpath( WP_CONTENT_DIR ) ) );
		foreach ( $public as $root ) {
			if ( '' === $parent || 0 === strpos( $parent . '/', rtrim( $root, '/' ) . '/' ) ) {
				$out['errors'][] = 'La carpeta de extracción no puede estar dentro de uploads, de WordPress ni de wp-content (es accesible por web).';
				return $out;
			}
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CHECKCONS ) ) {
			$out['errors'][] = 'El ZIP está dañado o no es un ZIP.';
			return $out;
		}
		try {
			$plan = self::plan( $zip );
			if ( $plan['errors'] ) {
				$out['errors'] = $plan['errors'];
				return $out;
			}
			if ( ! mkdir( $dest, 0700, true ) && ! is_dir( $dest ) ) {
				$out['errors'][] = 'No se pudo crear la carpeta temporal.';
				return $out;
			}
			$total = 0;
			foreach ( $plan['entries'] as $index => $name ) {
				$stream = $zip->getStream( $zip->getNameIndex( $index ) );
				$target = rtrim( $dest, '/' ) . '/' . $name;
				$fh     = $stream ? fopen( $target, 'xb' ) : false;
				if ( ! $stream || ! $fh ) {
					$out['errors'][] = "No se pudo leer $name.";
					break;
				}
				$size = 0;
				$fail = '';
				while ( ! feof( $stream ) ) {
					$chunk  = (string) fread( $stream, 65536 );
					$size  += strlen( $chunk );
					$total += strlen( $chunk );
					if ( $total > self::MAX_BYTES ) {
						$fail = 'El contenido descomprimido supera 100 MB.';
						break;
					}
					if ( $size > self::MAX_FILE ) {
						$fail = "$name supera 10 MB.";
						break;
					}
					fwrite( $fh, $chunk );
				}
				fclose( $fh );
				fclose( $stream );
				if ( '' === $fail ) {
					$fail = self::image_problem( $target, $name );
				}
				if ( '' !== $fail ) {
					$out['errors'][] = $fail;
					break;
				}
				$out['files'][ $name ] = $target;
			}
		} finally {
			$zip->close();
		}
		if ( $out['errors'] ) {
			$out['files'] = array();
			self::remove_dir( $dest ); // También el archivo parcial que falló.
		}
		return $out;
	}

	/**
	 * Tipo real y dimensiones (una imagen diminuta de comprimir pero enorme en píxeles agotaría la memoria al hacer miniaturas).
	 *
	 * @param string $path Archivo.
	 * @param string $name Nombre.
	 * @return string Problema o vacío.
	 */
	private static function image_problem( string $path, string $name ): string {
		$mime = self::real_mime( $path );
		if ( ! in_array( $mime, self::MIMES, true ) ) {
			return "$name no es una imagen JPG, PNG o WebP (tipo real: $mime).";
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE || $info[0] * $info[1] > self::MAX_PIXELS ) {
			return "$name tiene dimensiones no permitidas (máx. " . self::MAX_SIDE . ' px por lado y 40 megapíxeles).';
		}
		return '';
	}

	/**
	 * Borra una carpeta de extracción y su contenido (plano).
	 *
	 * @param string $dir Carpeta.
	 * @return void
	 */
	public static function remove_dir( string $dir ): void {
		if ( is_link( $dir ) ) {
			unlink( $dir ); // Un enlace simbólico se quita como enlace: nunca se sigue.
			return;
		}
		if ( ! is_dir( $dir ) || false === strpos( basename( $dir ), 'aralia-import-' ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $file ) {
			if ( '.' !== $file && '..' !== $file && ( is_file( $dir . '/' . $file ) || is_link( $dir . '/' . $file ) ) ) {
				unlink( $dir . '/' . $file );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Borra las carpetas de importación con más de DOS horas (el trabajo dura una hora: la purga nunca alcanza una confirmación lenta).
	 *
	 * @param int|null $now Marca de tiempo (pruebas).
	 * @return int Carpetas borradas.
	 */
	public static function purge_stale( ?int $now = null ): int {
		$count = 0;
		foreach ( (array) glob( trailingslashit( get_temp_dir() ) . 'aralia-import-*', GLOB_ONLYDIR ) as $dir ) {
			if ( ! is_link( $dir ) && ( $now ?? time() ) - (int) filemtime( $dir ) > 2 * HOUR_IN_SECONDS ) {
				self::remove_dir( $dir );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Revisa los nombres y tamaños declarados antes de extraer nada.
	 *
	 * @param \ZipArchive $zip ZIP abierto.
	 * @return array{entries:array<int,string>,errors:string[]}
	 */
	private static function plan( \ZipArchive $zip ): array {
		$plan  = array(
			'entries' => array(),
			'errors'  => array(),
		);
		$total = 0;
		$seen  = array();
		if ( $zip->count() > self::MAX_FILES ) {
			$plan['errors'][] = 'El ZIP tiene más de ' . self::MAX_FILES . ' archivos.';
			return $plan;
		}
		$count = $zip->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = false === $stat ? '' : (string) $stat['name'];
			if ( '' === $name || '/' === substr( $name, -1 ) ) {
				$plan['errors'][] = 'El ZIP contiene carpetas o nombres vacíos: sube solo las fotos, sin carpetas.';
				continue;
			}
			if ( false !== strpos( $name, '/' ) || false !== strpos( $name, '\\' ) || false !== strpos( $name, "\0" ) || false !== strpos( $name, '..' ) || ':' === ( $name[1] ?? '' ) ) {
				$plan['errors'][] = 'Ruta no permitida en el ZIP: ' . preg_replace( '/[^\x20-\x7E]/', '?', $name );
				continue;
			}
			if ( ! Validator::is_safe_filename( $name ) ) {
				$plan['errors'][] = 'Nombre de archivo no permitido: ' . $name;
				continue;
			}
			$key = strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				$plan['errors'][] = 'Nombre repetido en el ZIP: ' . $name;
				continue;
			}
			$seen[ $key ]          = true;
			$total                += (int) $stat['size'];
			$plan['entries'][ $i ] = $name;
		}
		if ( $total > self::MAX_BYTES ) {
			$plan['errors'][] = 'El contenido descomprimido supera 100 MB.';
		}
		return $plan;
	}

	/**
	 * Tipo real por contenido (no por extensión).
	 *
	 * @param string $path Archivo.
	 * @return string
	 */
	public static function real_mime( string $path ): string {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = $finfo ? (string) finfo_file( $finfo, $path ) : '';
		if ( $finfo ) {
			finfo_close( $finfo );
		}
		return $mime;
	}
}
