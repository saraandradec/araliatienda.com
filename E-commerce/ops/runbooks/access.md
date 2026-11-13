# Runbook — Accesos y cuentas temporales (PCI 8.2.2, 8.2.5; ISO 5.18; spec FR-078)

**Regla**: una sola administradora permanente (Sara). Nunca se comparte su cuenta. Quien necesite acceso técnico (desarrollo, soporte, revisora humana) recibe una cuenta **nominativa y temporal**.

## Alta

1. Sara confirma por escrito quién, para qué y hasta cuándo (máximo 30 días; renovable).
2. Crear la cuenta con el nombre real de la persona (nunca `admin`, `soporte` o `dev`) y el **rol mínimo**:
   - Revisión de código (revisora del camino de pago): permiso **Write** en el repo de GitHub, porque GitHub exige Write para figurar en CODEOWNERS y para que su aprobación cuente; sin acceso a WordPress ni a Hostinger. Sus PR no se fusionan sin pasar por Sara, y producción solo se despliega con `ops/deploy.sh` desde el equipo de Sara.
   - Al darla de alta: agregar su usuario en `ops/payment-reviewers.txt` y en las líneas `[PAGO]` de `.github/CODEOWNERS` (en un PR que ella misma aprueba).
   - Soporte en WordPress: rol `shop_manager`. Solo con `administrator` si es imprescindible, y con fecha de baja.
   - Servidor: llave SSH propia de la persona, agregada con comentario `nombre-fecha-baja`; nunca la llave de Sara.
   - **Llave de despliegue**: es de Sara, vive solo en su equipo (ssh-agent) y no se guarda en GitHub ni se comparte; si hace falta otra persona que despliegue, recibe su propia llave.
3. 2FA obligatorio antes del primer uso (Wordfence en WordPress, GitHub, Hostinger si aplica).
4. Registrar la alta en la tabla de abajo. El registro de actividad (`aralia_audit_log`) genera una alerta por cada usuario nuevo con rol elevado; Sara la confirma.

## Baja (el mismo día en que termina el trabajo, o en la fecha fijada)

1. Borrar o degradar la cuenta de WordPress y reasignar su contenido a Sara.
2. Quitar la llave SSH y el acceso a GitHub.
3. Si la persona vio secretos: rotarlos según `ops/runbooks/rotate-keys.md`.
4. Anotar la fecha de baja en la tabla.

## Revisión semestral

Cada 6 meses (y antes de la apertura), Sara revisa los usuarios de WordPress, GitHub, Hostinger, Wompi y Brevo, y elimina lo que no esté en la tabla o esté vencido.

| Persona | Para qué | Sistemas y rol | Alta | Baja prevista | Baja real |
|---------|----------|----------------|------|---------------|-----------|
| | | | | | |
