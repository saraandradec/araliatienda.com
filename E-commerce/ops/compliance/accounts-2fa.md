# Cuentas, 2FA y contraseñas (PCI 8.2–8.3, ISO 5.17, research R-19)

Reglas para **todas** las cuentas: 2FA activo (app TOTP o llave física; nunca SMS si hay alternativa), contraseña única de 12 caracteres o más guardada en el gestor de contraseñas, códigos de recuperación guardados en el gestor, sin cuentas compartidas.

| Cuenta | Dueña | 2FA | Contraseña ≥ 12 | Códigos de recuperación | Verificado (fecha) |
|--------|-------|-----|-----------------|-------------------------|--------------------|
| Hostinger (panel) | Sara | ☐ | ☐ | ☐ | |
| Registrador del dominio (+ DNSSEC si lo ofrece) | Sara | ☐ | ☐ | ☐ | |
| Correo de Sara (recibe alertas y recuperaciones) | Sara | ☐ | ☐ | ☐ | |
| Wompi (panel de comercio) | Sara | ☐ | ☐ | ☐ | |
| Brevo | Sara | ☐ | ☐ | ☐ | |
| Meta Business | Sara | ☐ | ☐ | ☐ | |
| Google (GA4 / Search Console / Drive de copias) | Sara | ☐ | ☐ | ☐ | |
| GitHub (`saraandradec`) | Sara | ☐ | ☐ | ☐ | |
| WordPress (administradora) | Sara | ☐ (Wordfence) | ☐ | ☐ | |
| Cloudflare (Turnstile) | Sara | ☐ | ☐ | ☐ | |

Otros controles:

- ☐ No existe ningún usuario llamado `admin` en WordPress, Hostinger ni la base de datos.
- ☐ El repositorio `saraandradec/araliatienda.com` es **privado** y GitHub exige 2FA a quien tenga acceso.
- ☐ **Plan Free de GitHub (decisión 2026-11-13)**: en repos privados no hay protección de rama ni environments protegidos. Compensación: nadie más que Sara tiene permiso de escritura mientras no se contrate a la revisora; sin secretos de despliegue en GitHub (Settings → Secrets vacío de llaves SSH); el despliegue lo hace `ops/deploy.sh` desde su equipo, que exige CI verde, checksum y PR aprobado por `ops/payment-reviewers.txt`.
- ☐ **Condición de la primera salida a producción (T166)**: pasar a GitHub Pro (cuenta personal) o Team para tener protección de rama (sin push directo ni force-push a `main`) y CODEOWNERS obligatorio. Hasta entonces `ops/deploy.sh` es el único control.
- ☐ **Condición de la primera salida a producción (T166)**: staging en una cuenta/usuario de Hostinger distinta de producción, con su propia llave SSH (el código de staging no pasa por la revisión de producción). `ops/deploy.sh` lo comprueba.
- ☐ La llave de despliegue ed25519 tiene frase de contraseña, está en el llavero de macOS y existe copia en el gestor de contraseñas.
