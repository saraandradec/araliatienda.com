# Matriz de responsabilidades (PCI 12.8.5, ISO 5.23)

R = responsable · C = consultado · — = no aplica

| Control | Wompi (TPSP) | Hostinger | Aralía (Sara + desarrollo) |
|---------|--------------|-----------|----------------------------|
| Captura y procesamiento de datos de tarjeta y cuenta (widget e iframe alojados) | R | — | — |
| Cumplimiento PCI del TPSP (AOC anual) | R | — | C (solicitar y archivar) |
| Integridad de la página order-pay y de los scripts del sitio (criterio E7) | C (`widget.js`) | — | R |
| Firma de integridad y verificación de eventos | C | — | R (`aralia-wompi-guard`) |
| Custodia de llaves y secretos | — | C (sistema de archivos) | R |
| Reembolsos y anulaciones | R (ejecuta en su panel) | — | R (Sara los ordena y registra) |
| Infraestructura del servidor, red, parches del sistema operativo y de PHP | — | R | C |
| WAF y CDN | — | R (de su plan) | R (Wordfence, configuración) |
| Parches de WordPress, plugins y tema | — | — | R (SLA ≤ 7 días) |
| Copias de seguridad | — | R (diarias del plan) | R (copia externa cifrada y restauración probada) |
| Escaneo ASV trimestral | — | — | R (contratar o vía Wompi) |
| Respuesta a incidentes | C (fraude, pagos) | C (servidor) | R (plan y aviso a la SIC) |
| Protección de datos personales (Ley 1581) | R (sus datos) | R (encargado) | R (responsable del tratamiento) |

Revisión: anual o cuando cambie un proveedor. Última: 2026-11-03 (borrador; confirmar el reparto de Hostinger según su plan contratado).
