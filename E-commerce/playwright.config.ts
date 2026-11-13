import { defineConfig, devices } from '@playwright/test';

/**
 * E2E de Aralía: móvil primero (360 px). BASE_URL apunta a wp-env (local/CI) o a staging (eventos reales de Sandbox).
 * Las trazas solo se guardan al reintentar y nunca con estado de sesión (playwright/.auth está en .gitignore).
 */
export default defineConfig( {
	testDir: 'tests/e2e',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: [ [ 'html', { open: 'never', outputFolder: 'playwright-report' } ], [ 'list' ] ],
	use: {
		baseURL: process.env.BASE_URL ?? 'http://localhost:8888',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		locale: 'es-CO',
		timezoneId: 'America/Bogota',
	},
	projects: [
		{
			name: 'movil-360',
			use: { ...devices[ 'Pixel 5' ], viewport: { width: 360, height: 780 } },
		},
		{
			name: 'escritorio',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
