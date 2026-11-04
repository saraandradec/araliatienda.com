# Clasificación de datos — Aralía Beauty Store P0

**Feature**: [spec.md](./spec.md) · **Tier**: T0 · **Fecha**: 2026-11-03
**Marco**: Ley 1581 de 2012 y Decreto 1074 de 2015 (protección de datos personales, Colombia); PCI DSS 4.0 para datos de pago; Estatuto del Consumidor (Ley 1480 de 2011) para evidencia de ventas a distancia.

## Niveles

| Nivel | Definición | Ejemplos |
|-------|------------|----------|
| **R — Restringido** | Su exposición permite fraude o control del sistema | Secretos de pasarela, credenciales, sesiones de administración |
| **C — Confidencial (PII)** | Dato personal de una titular identificable | Nombre, celular, correo, dirección, datos de quien recibe un regalo |
| **I — Interno** | Información comercial no pública | Costo, margen, reportes de ventas |
| **P — Público** | Publicado a propósito | Catálogo, precios, contenido |

## Inventario

| Dato | Nivel | ¿PII? | ¿Pago/PCI? | Dónde vive | Retención | Notas |
|------|-------|-------|------------|-----------|-----------|-------|
| PAN, CVV, fecha de vencimiento, datos de cuenta PSE/Nequi | — | — | **Sí (CHD/SAD)** | **Nunca en la tienda**: solo dentro del widget alojado por Wompi (checkout.wompi.co) | N/A | Fuera de alcance de almacenamiento (SAQ A). FR-050 |
| Id de transacción, medio de pago, estado, últimos 4 dígitos/marca si Wompi los envía | C | Indirecta | Metadato de pago (no CHD) | Pedido | Igual que el pedido | No mostrar más que marca y últimos 4 |
| Llave privada, secreto de integridad y secreto de eventos de Wompi | **R** | No | Afecta seguridad del pago | Servidor, fuera de la raíz web y del repositorio | Hasta rotación | FR-079, I-1 |
| Llave pública de Wompi | P | No | No | Navegador | — | Es pública por diseño |
| Credenciales SMTP/SMS, API de conversiones, claves anti-bots | R | No | No | Servidor | Hasta rotación | |
| Credenciales y sesión de la administradora, semilla 2FA | R | Sí | No | Servidor | Mientras exista la cuenta | |
| Nombre, celular, correo de la compradora | C | Sí | No | Pedido, proveedor de correo/SMS | 10 años como soporte contable del pedido (Código de Comercio art. 60); datos de marketing hasta revocar | Finalidad: cumplir el pedido |
| Dirección, ciudad, indicaciones de entrega | C | Sí | No | Pedido, operador de envío | Igual que el pedido | |
| Nombre y celular de quien recibe un regalo, mensaje de tarjeta | C | **Sí, de un tercero** | No | Pedido | Celular y mensaje: 90 días después de la entrega (research R-23); nombre: igual que el pedido | Tercera titular: se usa solo para la entrega; aviso en el checkout de que la compradora declara tener autorización |
| Texto de vinilo del neceser | C | Posible (suele ser un nombre) | No | Pedido | Igual que el pedido | |
| Correo/celular para "Avísame cuando vuelva" | C | Sí | No | Lista de avisos | Hasta enviar el aviso + 90 días, o hasta revocar | Consentimiento propio |
| Correo/celular de la lista de acceso anticipado | C | Sí | No | Lista | Hasta revocar | Consentimiento de marketing |
| Prueba de consentimiento (texto, versión, fecha, canal, IP) | C | Sí | No | Registro de consentimientos | Mientras dure el tratamiento + 5 años | FR-075 |
| Tono guardado | P/I | No (en el dispositivo, sin identificar) | No | Almacenamiento local del navegador | 12 meses | FR-081 |
| Carrito persistente | I | No en el navegador; sí si se asocia a cuenta | No | Navegador / cuenta | 30 días | FR-045 |
| IP, agente de usuario y huella anti-bots | C | Sí (indirecta) | No | Registros de seguridad | 90 días | Finalidad: seguridad y antifraude |
| Registro de eventos de pago (`raw_sanitized` con lista blanca: sin correo, nombre, teléfono, BIN ni titular) | C | Indirecta | Metadato | `aralia_payment_events` | 5 años | R-1, evidencia de disputas; contracts/wompi §3 |
| Intentos de pago (`_ara_attempts`: referencia, id de transacción, estado) | C | Indirecta | Metadato | Pedido | Igual que el pedido | |
| `client_id` de GA4, `fbp`/`fbc` en el pedido | C (seudónimo) | Sí | No | Pedido | 90 días | Solo con consentimiento de analítica (R-14) |
| Sesión de WooCommerce de invitada (carrito y datos del checkout) | C | Sí, si llenó el checkout | No | `wc_sessions` | 30 días (FR-045) | Se purga al vencer |
| Credencial de la cuenta de servicio de GA4 Data API (solo lectura) | **R** | No | No | `aralia-secrets.php` | Hasta rotación | R-14 |
| `ARALIA_PII_KEY` y llave HMAC de `subject_hash` | **R** | No | No | `aralia-secrets.php` + gestor de contraseñas de Sara | Rotación anual | R-16, R-23 |
| Registro de actividad del panel | I | Sí (la administradora) | No | Base de datos | 1 año como mínimo | R-3 |
| Correo/celular con hash SHA-256 para la API de conversiones | C (seudónimo) | Sí | No | Meta (tercero) | Según Meta | Declarar en la política de privacidad como transferencia/transmisión |
| Costo de compra y margen | **I** | No | No | Metadato privado del producto | Indefinida | Nunca en API pública ni HTML (FR-067, I-3) |
| Ventas, conversión, ticket promedio | I | No (agregado) | No | Panel, GA4 | Indefinida | |
| Catálogo, precios, fotos, textos | P | No | No | Sitio | — | |

## Reglas derivadas

1. **Nada de CHD/SAD en la tienda**: ningún campo del checkout pide datos de tarjeta; ningún registro, correo ni analítica los contiene. Verificable por revisión de código y de la base (PCI SAQ A, criterio de elegibilidad).
2. **Minimización**: el checkout pide solo lo necesario para entregar (FR-047); la compra como invitada no crea cuenta.
3. **Finalidades declaradas** en la política de tratamiento: cumplir el pedido, avisos transaccionales, avisos de stock solicitados, marketing solo con autorización separada, seguridad y antifraude, analítica.
4. **Encargados (terceros que procesan PII)**: Wompi, Hostinger, proveedor de correo, proveedor de SMS/WhatsApp, operador de envío, Google (GA4), Meta. Cada uno debe aparecer en la política y, cuando aplique, tener contrato o términos de encargo; las transferencias internacionales (Google, Meta, proveedores en EE. UU.) deben declararse.
5. **Derechos de la titular** (consultar, corregir, suprimir, revocar): canal en la política y procedimiento en el panel (FR-083), respuesta en los plazos legales (10 días hábiles consultas, 15 reclamos).
6. **Registro Nacional de Bases de Datos (RNBD)**: verificar con el abogado si aplica según la figura legal y los activos (decisión 14-11).
7. **Cifrado**: todo en tránsito con TLS 1.2+; copias de seguridad cifradas; secretos (R) nunca en texto plano dentro del repositorio.
8. **Datos de prueba**: en Sandbox y staging solo datos ficticios; nunca copiar PII de producción a staging sin anonimizar.
