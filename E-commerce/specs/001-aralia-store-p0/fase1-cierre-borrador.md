# Cierre de la Fase 1 (T169) — BORRADOR para revisión y firma de Sara

> Este documento es un borrador preparado por Claude. **No es una aprobación.** Nada de lo que sigue está registrado en `.trace/ledger.jsonl` ni la constitución ha cambiado de estado hasta que Sara lo apruebe expresamente.

## 1. Decisiones que requieren tu firma

1. **Constitución 1.2.0** (hoy PROPUESTA): amplía la revisión humana a todo el repo salvo `specs/` e `ops/inventory.md`; lanzador de confianza local; despliegue solo de código propio; condiciones de primera salida (T166). ¿Se aprueba? ☐ Sí ☐ No ☐ Con cambios: ______
2. **Riesgos residuales RR-8 a RR-15** (descritos en `ops/runbooks/deploy.md`, «Riesgos residuales», y en `threat-model.md`): ¿los aceptas? ☐ Sí ☐ No (indica cuáles): ______
3. **Producción sigue bloqueada** por diseño hasta: revisora contratada en `ops/payment-reviewers.txt`, staging en cuenta separada y protección de rama (GitHub Pro/Team) o aceptación escrita `ALLOW_*`.

## 2. Resumen de las vueltas de gates de la Fase 1

| Vuelta | Resultado |
|---|---|
| 1–3 | Hallazgos altos corregidos (rsync --delete, cargador de secretos, puerta de revisión); adversarial 3+3 confirmó H-1/H-2 |
| 4–8 | Despliegue local y de confianza, lista cerrada de terceros, inventario por archivos, rollback verificado |
| 9 | architect NO APROBADO (A-1: primer despliegue imposible); code-reviewer APROBADO; security sin críticos/altos |
| 10 | A-1 y medios corregidos; filtro de secretos de CI ampliado; RR-15 |
| 11 | architect aprobado con observaciones; code-reviewer APROBADO; security sin críticos/altos |
| 12 | Observaciones medias corregidas; verificación puntual del arquitecto: APROBADO con observaciones |

Verificación técnica: shellcheck limpio y 294 pruebas de `tests/ops/deploy_test.sh` OK (Docker php:8.3-cli). Pendiente del ledger: ejecutar el trace-ledger tras tu firma.

## 3. Entradas de ledger propuestas (NO escritas)

Una por vuelta (`stage`: `logidev:fase1:vuelta-N`), con `gates` según la tabla y `signed_off_by` = tu nombre solo cuando firmes. Más una entrada final `logidev:fase1:cierre` con `notes`: constitución 1.2.0 aprobada, RR-8 a RR-15 aceptados y SHA-256 del lanzador instalado.

## 4. Pasos tuyos pendientes

- Revocar los 2 tokens de GitHub pegados en el chat el 2026-11-13.
- Confirmar que el secreto `ARALIA_CI_SECRETS_SANDBOX` solo trae credenciales de prueba.
- Instalar el lanzador (`ops/runbooks/deploy.md`, «Instalar el lanzador») y anotar su SHA-256.
- T012, T014, T015, T016, T018, T019 (hosting, 2FA, AOC, Brevo, DNS, staging).
