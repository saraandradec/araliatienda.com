<?php
/**
 * El limitador no pudo contar (error de base de datos).
 *
 * @package Aralia
 */

namespace Aralia\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Quien usa RateLimiter debe FALLAR CERRADO ante esta excepción (nunca tratarla como "0 golpes").
 */
class RateLimitUnavailable extends \RuntimeException {
}
