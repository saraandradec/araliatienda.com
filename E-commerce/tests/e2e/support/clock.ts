import { execFileSync } from 'node:child_process';

/**
 * Reloj simulado de Action Scheduler para las pruebas E2E (T163).
 * Adelanta `minutes` las acciones pendientes de `hook` y ejecuta las vencidas, dentro del entorno de pruebas de wp-env.
 * Sin shell: los argumentos se pasan como lista y se validan.
 */
function assertLocalTarget(): void {
  // El reloj actúa sobre el wp-env local de pruebas: si los E2E apuntan a otro sitio (staging), no tiene sentido y es peligroso.
  const base = process.env.BASE_URL;
  if (base && !/^https?:\/\/(localhost|127\.0\.0\.1):8889(\/|$)/.test(base)) {
    throw new Error(`El reloj simulado actúa sobre el entorno de pruebas de wp-env (puerto 8889); BASE_URL=${base}`);
  }
}

export function advanceClock(hook: string, minutes: number): string {
  assertLocalTarget();
  if (!/^[a-z0-9_]{1,80}$/.test(hook)) {
    throw new Error(`Hook inválido: ${hook}`);
  }
  if (!Number.isInteger(minutes) || minutes < 0 || minutes > 60 * 24 * 365) {
    throw new Error(`Minutos inválidos: ${minutes}`);
  }
  return execFileSync(
    'npx',
    [
      'wp-env', 'run', 'tests-cli', '--env-cwd=wp-content/aralia-repo',
      'wp', 'eval-file', 'tests/php/Support/clock-advance.php', hook, String(minutes),
    ],
    { encoding: 'utf8' },
  );
}

/**
 * Vuelve el reloj de la tienda a la hora real. Llamar en afterEach: el desplazamiento se guarda en la base de datos y,
 * si no se limpia, se acumula entre pruebas (un pedido nuevo se vería como vencido).
 */
export function resetClock(): string {
  assertLocalTarget();
  return execFileSync(
    'npx',
    ['wp-env', 'run', 'tests-cli', '--env-cwd=wp-content/aralia-repo', 'wp', 'eval-file', 'tests/php/Support/clock-advance.php', 'reset'],
    { encoding: 'utf8' },
  );
}
