# Mapeo de cumplimiento — Aralía Beauty Store P0

**Feature**: [spec.md](./spec.md) · **Tier**: T0 · **Fecha**: 2026-11-03 · **Generado por**: `compliance-mapper`
**Entradas**: `spec.md`, `threat-model.md` (TM), `data-classification.md` (DC), `aralia-ecommerce-requerimientos.md`
**Límite de este documento**: es una guía de diseño para no llegar con sorpresas a una autoevaluación o auditoría. No es una atestación PCI ni una certificación ISO. Donde el identificador exacto de un control no está confirmado, se indica.

---

## 0. Corrección importante al punto de partida

**6.4.3 y 11.6.1 ya no están en el SAQ A.** En enero de 2025 (SAQ A v4.0.1 r1) el PCI SSC retiró del cuestionario los requisitos **6.4.3, 11.6.1 y 12.3.1** y agregó un **criterio de elegibilidad**: el comercio confirma que *su sitio no es susceptible a ataques de scripts que puedan afectar sus sistemas de e-commerce*. El criterio cubre **todo el sitio**, no solo la página de pago. La vía práctica y más defendible para cumplirlo es aplicar igualmente las técnicas de 6.4.3 y 11.6.1, por eso se mantienen aquí como **medio para conservar la elegibilidad**. La fecha exacta de vigencia de r1 debe confirmarse en el documento oficial del PCI SSC.

---

## 1. Tipo de SAQ y criterios de elegibilidad

**SAQ aplicable: SAQ A** (e-commerce cuyos campos de pago viven íntegramente en el componente alojado por un TPSP que cumple PCI DSS: aquí, el widget de Wompi abierto desde order-pay; condicionado a la evidencia de research R-19). El diseño debe mantener **todos** estos criterios; si se rompe uno, el SAQ pasa a A-EP o D.

| # | Criterio de elegibilidad SAQ A (v4.0.1) | Cómo lo sostiene el diseño | Estado | Riesgo de romperlo |
|---|---|---|---|---|
| E1 | Solo transacciones con tarjeta no presente | Tienda en línea únicamente | Cubierto | Aceptar tarjetas por WhatsApp, teléfono o datáfono en el mismo comercio |
| E2 | Todo el procesamiento de datos de cuenta tercerizado a un TPSP que cumple PCI DSS | FR-050, FR-051 (widget alojado por Wompi) | Cubierto | — |
| E3 | El comercio no almacena, procesa ni transmite electrónicamente datos de cuenta | FR-050, FR-081, DC regla 1; registro de eventos saneado (solo marca y últimos 4) | Cubierto | Que un plugin, registro o correo capture datos de tarjeta; que se use tokenización o un formulario de pago propio en el DOM de la tienda (SAQ A-EP) |
| E4 | El comercio confirmó el cumplimiento PCI DSS del TPSP | RR-4 (AOC anual de Wompi) | **Parcial** | Falta obtener y archivar el AOC de Wompi **antes de abrir** |
| E5 | Datos de cuenta solo en papel y nunca recibidos electrónicamente | — | **Huérfano** | No hay regla que prohíba recibir números de tarjeta por WhatsApp, DM o correo |
| E6 | Todos los elementos de la página de pago vienen solo y directamente del TPSP | Widget oficial de Wompi: los campos de pago viven en el componente alojado en checkout.wompi.co (FR-050) | **Verificado: widget, no redirección** (código del plugin v3.2.1, 3 nov 2026) | La página de la tienda que abre el widget (order-pay) carga `widget.js` de Wompi → E7 aplica con rigor a esa página; ver research.md R-01 |
| E7 | (r1 2025) El sitio no es susceptible a ataques de scripts que afecten el e-commerce | T-3, T-10, T-11, FR-073, TM §4.3, research R-11 (CSP en todo el sitio, inventario, monitor diario con contenido de `widget.js`) | **Cubierto por el plan** (ver §7) | GA4 y Meta Pixel solo en páginas públicas, con hashes de inline y `connect-src` restringido |

**Requisitos del SAQ A v4.0.1** (lista a cotejar con el PDF oficial; 11.3.2 confirmado):

| Requisito | Qué exige | Cobertura | Estado | Gap |
|---|---|---|---|---|
| 2.2.2 | Cuentas por defecto cambiadas o eliminadas | TM §4.4 | Parcial | Regla explícita: sin usuario `admin`, credenciales y prefijo de BD propios, sin cuentas por defecto en Hostinger |
| 2.2.5 | Servicios y protocolos inseguros deshabilitados | TM §4.4 (sin XML-RPC), I-7 | Cubierto | Documentarlo en la línea base |
| 2.2.7 | Acceso administrativo no de consola cifrado | DC regla 7, E-4 | Cubierto | — |
| 3.1.1 / 3.2.1 | Política de retención de datos de cuenta | FR-050, DC regla 1 | Cubierto | Declarar por escrito "no se retienen datos de cuenta"; no guardar el payload crudo del evento con más que marca y últimos 4 (FR-054) |
| 6.3.1 | Identificar y clasificar vulnerabilidades con fuentes reconocidas | T-9 | Parcial | Definir fuente (feed de vulnerabilidades de WP/plugins) y criterio |
| 6.3.3 | Parches críticos/altos en ≤ 1 mes | T-9, TM §4.10 | Parcial | SLA explícito |
| 8.2.1 / 8.2.2 | IDs únicos; sin cuentas compartidas | FR-078 | **Parcial / posible contradicción** | Si alguien de desarrollo o soporte necesita acceso, "una sola cuenta" empuja a compartir la de Sara. Hace falta cuenta nominativa temporal |
| 8.2.5 | Revocar accesos de quien sale | — | Huérfano | Baja documentada de cuentas temporales |
| 8.3.1, 8.3.5–8.3.7, 8.3.9 | Factores de autenticación, contraseñas ≥ 12, sin reutilizar | FR-078, S-3 | Cubierto en el panel WP | Extender a Hostinger, registrador, correo de Sara y **panel de Wompi** |
| 9.4.x | Medios en papel con datos de cuenta | — | N/A, **condicionado a E5** | Prohibir anotar tarjetas en papel |
| 11.3.2 / 11.3.2.1 | Escaneo externo trimestral por **ASV** y tras cambios significativos | — | **Huérfano** | Contratar ASV (preguntar a Wompi si lo ofrece) |
| 12.8.1–12.8.5 | Lista de TPSP, acuerdo, diligencia, monitoreo anual, matriz de responsabilidades | DC regla 4, RR-4 | Parcial | Lista PCI de TPSP (Wompi, Hostinger, CDN/WAF, autor del plugin) y matriz de quién hace qué |
| 12.10.1 | Plan de respuesta a incidentes | — | **Huérfano** | Plan de incidentes |
| (6.4.3 / 11.6.1) | Medio para cumplir E7 | Ver §2 | Parcial | Ver E7 |

---

## 2. PCI DSS v4.0.1 (énfasis en Requisito 6)

"¿SAQ A?": **Sí** = ítem del cuestionario · **Elig.** = necesario para la elegibilidad · **BP** = buena práctica útil para T0.

| Control | ¿SAQ A? | Cobertura | Estado | Gap / acción |
|---|---|---|---|---|
| 6.1.1 / 6.1.2 Políticas y roles del Req. 6 | BP | — | Huérfano (bajo) | Una página "cómo se actualiza y revisa", responsable Sara |
| 6.2.1 Software a medida seguro | BP | TM §4 | Parcial | Aplica al tema y a plugins propios; exige control de versiones |
| 6.2.2 Formación de quien desarrolla | BP | — | Huérfano (bajo) | Declaración de conocer OWASP Top 10 y guías de seguridad de WP |
| 6.2.3 / 6.2.3.1 Revisión de código antes de producción | BP | SC-015 | Parcial | Revisión obligatoria de checkout, eventos de pago y estados del pedido |
| 6.2.4 Técnicas contra ataques comunes | BP | T-1, T-5, T-7, E-3, I-2, FR-041, FR-053, TM §4.9 | Cubierto | Nombrar CSRF (nonces de WP) |
| 6.3.1 | **Sí** | T-9 | Parcial | Ver §1 |
| 6.3.2 Inventario de componentes | BP | LC-8, T-9 | Parcial | Inventario versionado: núcleo, plugins, tema, librerías JS |
| 6.3.3 | **Sí** | T-9 | Parcial | SLA |
| 6.4.1 / 6.4.2 WAF | BP | E-1, RR-3 | Cubierto en intención | Definir qué WAF y en qué modo |
| 6.4.3 Scripts de la página de pago | Elig. (E7) | T-3, T-10, FR-073, TM §4.3, R-11 | Cubierto por el plan | Con widget, la "página de pago" es order-pay (carga `widget.js`), además de checkout y Gracias: CSP estricta con nonce, sin terceros salvo Wompi (y Turnstile solo en checkout) |
| 11.6.1 Detección de cambios en cabeceras y scripts | Elig. (E7) | T-3 | Parcial | Frecuencia al menos semanal y destinataria de la alerta |
| 6.5.1 / 6.5.2 Gestión de cambios | BP | TM §4.10 | Parcial | Registro mínimo de cambios (qué, cuándo, prueba, vuelta atrás) |
| 6.5.3 Preproducción separada | BP | T-9 | Cubierto | — |
| 6.5.4 Separación de roles | BP | RR-1 | Riesgo aceptado | Compensado con R-3 |
| 6.5.5 Sin datos reales en preproducción | BP | DC regla 8 | Cubierto | — |
| 6.5.6 Datos y cuentas de prueba eliminados | BP | SC-011 | Parcial | Checklist de salida: llaves sandbox fuera, pedidos/usuarios de prueba borrados, modo prueba apagado |
| 4.2.1 Cifrado en tránsito | BP | DC regla 7 | Cubierto | — |
| 10.x Registros | BP | FR-054, R-3, FR-078 | Cubierto | — |
| 11.3.2 ASV | **Sí** | — | **Huérfano** | Ver §1 |
| 12.8.x | **Sí** | RR-4, DC regla 4 | Parcial | Ver §1 |
| 12.10.1 | **Sí** | — | **Huérfano** | Ver §1 |
| Custodia de llaves | — | FR-058 (reformulado), FR-079, I-1, TM §4.8, R-01 H6/H7/H13, R-16 | Cubierto por el plan | El plugin exige la llave privada (verificado); se trata como R, fuera de la BD y del repo; la anulación desde el panel está neutralizada (H13); la llave de consulta la decide T-API-AUTH |

---

## 3. NIST SSDF SP 800-218 v1.1

| Práctica | Cobertura | Estado | Gap / acción |
|---|---|---|---|
| PO.1.1 Requisitos de seguridad | FR-050–FR-060, FR-073, FR-078–FR-083, TM §4 | Cubierto | — |
| PO.1.3 Requisitos a terceros | LC-8, DC regla 4 | Parcial | Criterios de aceptación de plugins: mantenido, actualizado en los últimos 6 meses, sin CVE abiertas |
| PO.3 Cadena de herramientas | T-9 | Parcial | Definir escáner y dónde corre |
| PO.4 Criterios de verificación | SC-010, SC-015 | Parcial | Criterios de salida: escaneo sin críticos, ASV aprobado, restauración probada |
| PO.5.1 Entornos separados | T-9, DC regla 8 | Cubierto | — |
| PO.5.2 Endpoints de desarrollo/administración seguros | E-4 | Parcial | Equipo de Sara actualizado, bloqueo de pantalla, gestor de contraseñas |
| PS.1.1 Código protegido (control de versiones) | I-1 | Parcial | `E-commerce/` vive dentro del repo git `Aralía-web/` (aún sin commit ni remoto). Falta remoto privado con 2FA para tema y plugins propios, y `.gitignore` de secretos |
| PS.2.1 Integridad de lo publicado | — | Huérfano (bajo) | Plugins solo desde wp.org o el proveedor oficial; `wp core verify-checksums` / `wp plugin verify-checksums` |
| PS.3.1 Archivar versiones | FR-082 | Parcial | Etiquetas de versión en el repo |
| PW.1.1 Modelado de riesgo | `threat-model.md` | Cubierto | — |
| PW.2.1 Revisión del diseño | Este mapeo + arquitecto | Cubierto | — |
| PW.4.1 / PW.4.4 Componentes asegurados | T-9 | Parcial | Inventario (6.3.2) y revisión por componente |
| PW.5.1 Codificación segura | T-5, FR-041, FR-053, TM §4.9 | Cubierto | Nonces, `$wpdb->prepare`, comprobación de capabilities |
| PW.7.1 / PW.7.2 Revisión y análisis de código | SC-015 | Parcial | Revisión humana + análisis estático (PHPCS con WordPress-Extra/VIP security) |
| PW.8.2 Pruebas dinámicas | SC-010, SC-011, D-3 | Parcial | Pruebas negativas de la máquina de estados, IDOR (I-2/E-3), DAST básico en staging |
| PW.9.1 / PW.9.2 Configuración segura por defecto | TM §4.4, I-5, E-2 | Cubierto | Documentar línea base |
| RV.1.1 Vigilar vulnerabilidades | T-9 | Parcial | Feed de vulnerabilidades WP con alertas |
| RV.1.3 Divulgación de vulnerabilidades | — | Huérfano (bajo) | `/.well-known/security.txt` |
| RV.2.1 / RV.2.2 Corregir | T-9 | Parcial | Mismo SLA que 6.3.3 |
| RV.3 Causa raíz | — | Huérfano (bajo) | Nota post-incidente en el plan de incidentes |

---

## 4. ISO/IEC 27001:2022, Anexo A

| Control | Cobertura | Estado | Gap / acción |
|---|---|---|---|
| 5.3 Segregación de funciones | RR-1 | Riesgo aceptado | Compensado con R-3 y R-4 |
| 5.9 / 5.12 Inventario y clasificación | DC, A1–A9 | Cubierto | — |
| 5.14 Transferencia de información | LC-5, LC-6, I-4, I-8 | Cubierto | — |
| 5.15 Control de acceso | FR-078, E-2, E-3, I-2 | Cubierto | — |
| 5.17 Información de autenticación | S-3, FR-078 | Parcial | 2FA en Hostinger, registrador, correo, Wompi, SMS, Meta Business; custodia de códigos de recuperación |
| 5.18 Derechos de acceso | — | Huérfano | Cuentas temporales y revisión semestral de usuarios |
| 5.19 Relación con proveedores | DC regla 4 | Parcial | Lista de proveedores con criticidad |
| 5.20 Acuerdos con proveedores | DC regla 4 | Parcial | Revisar términos: tratamiento de datos y notificación de incidentes |
| 5.21 Cadena de suministro TIC | LC-8, T-9 | Parcial | Criterios de selección de plugins |
| 5.22 Monitoreo de proveedores | RR-4 | Parcial | Revisión anual: AOC, cambios o cambio de dueño de plugins |
| 5.23 Servicios en la nube | RR-3 | Parcial | Matriz compartida Hostinger / Aralía (= 12.8.5) |
| 5.24–5.27 Gestión de incidentes | — | **Huérfano** | Plan de incidentes con aviso a la SIC |
| 5.29 / 5.30 Continuidad | FR-082, D-3 | Parcial | RPO 24 h / RTO 8 h |
| 5.31 Requisitos legales | DC, FR-074–FR-077 | Parcial | Ley 2300 de 2023 y reversión del pago (Ley 1480 art. 51), ver §6 |
| 5.33 Protección de registros | DC | Cubierto | — |
| 5.34 Privacidad y PII | FR-075, FR-083, DC | Parcial | Ver §6 |
| 8.2 Accesos privilegiados | FR-078, E-1 | Cubierto | — |
| 8.3 Restricción de acceso | I-2, I-3, I-7, FR-067 | Cubierto | — |
| 8.5 Autenticación segura | FR-078, S-3 | Cubierto | — |
| 8.7 Malware | WAF, T-9, E-5 | Parcial | Escaneo periódico de malware en archivos |
| 8.8 Vulnerabilidades técnicas | T-9 | Parcial | SLA y ASV |
| 8.9 Configuración | TM §4.4 | Parcial | Línea base escrita y verificable |
| 8.10 Eliminación de información | DC, FR-083 | Parcial | Purga programada |
| 8.12 Prevención de fuga | FR-073, I-4, I-6, I-8 | Cubierto | — |
| 8.13 Copias de seguridad | FR-082, I-6 | Parcial | Una copia **fuera de Hostinger**; restauración trimestral |
| 8.15 Registro de eventos | FR-054, FR-078, R-3 | Cubierto | Exportación periódica para protegerlos de borrado |
| 8.16 Monitoreo | D-2, D-6, E-1, T-3 | Parcial | Destinataria y canal de cada alerta |
| 8.17 Sincronización de relojes | UTC−5 | Cubierto en lo práctico | — |
| 8.20 / 8.21 Redes | TLS, WAF, CDN | Parcial | HSTS; DNSSEC y 2FA en el registrador |
| 8.22 / 8.23 | — | N/A | Hosting administrado |
| 8.24 Criptografía | DC regla 7, T-2, S-1 | Cubierto | Comparación de firmas con `hash_equals` |
| 8.25 Ciclo de vida seguro | TM, este mapeo | Parcial | Ver PS.1, PW.7 |
| 8.26 Requisitos de seguridad | FR-050–FR-060, FR-078–FR-081 | Cubierto | — |
| 8.27 Arquitectura segura | TM | Cubierto | El plan debe concretarlo |
| 8.28 Codificación segura | T-5, FR-041, FR-053 | Cubierto | Ver PW.5.1 |
| 8.29 Pruebas de seguridad | SC-010, SC-011, SC-015 | Parcial | Ver PW.8.2 |
| 8.31 Separación de entornos | T-9 | Cubierto | — |
| 8.32 Gestión de cambios | TM §4.10 | Parcial | Registro de cambios |
| 8.33 Información de prueba | DC regla 8 | Cubierto | — |

---

## 5. Huérfanos y parciales que `plan.md` DEBE cubrir (por prioridad)

**Bloqueantes de elegibilidad SAQ A / T0**

1. **Modo real del plugin de Wompi (E6)**: ~~confirmar redirección~~ → verificado: **widget**. Queda la evidencia de que los campos viven en un iframe de Wompi (R-19, puerta de la fase 1).
2. **Llaves de Wompi frente a FR-058**: qué llaves exige el plugin, qué permite cada una, si la consulta de estado funciona sin la privada; ajustar FR-058 o la integración.
3. **Criterio de scripts en todo el sitio (E7)**: inventario justificado, CSP en todo el sitio (más estricta en checkout y Gracias), SRI, detección de cambios de scripts y cabeceras al menos semanal con alerta a Sara.
4. **AOC de Wompi archivado antes de abrir** + matriz de responsabilidades Wompi / Hostinger / Aralía.
5. **Escaneo ASV trimestral** y tras cambios significativos.
6. **Plan de respuesta a incidentes** de 1–2 páginas con aviso a la SIC y a las titulares.
7. **Prohibición escrita de recibir datos de tarjeta** por WhatsApp, DM, correo o teléfono, con respuesta modelo.

**Altos**

8. SLA de parcheo (críticos/altos ≤ 7 días) y feed de vulnerabilidades.
9. Inventario de componentes con versión y justificación.
10. Control de versiones: remoto privado con 2FA, sin secretos, versiones etiquetadas.
11. Revisión de código del camino de pago por alguien distinto del autor + análisis estático.
12. 2FA y contraseñas ≥ 12 fuera de WP; cuenta nominativa temporal para soporte con baja documentada; sin usuario `admin`.
13. Línea base de configuración escrita (incluye HSTS y cabeceras).

**Medios**

14. Copia fuera de Hostinger, RPO/RTO, restauración trimestral.
15. Checklist de salida a producción (6.5.6).
16. Pruebas negativas: transiciones prohibidas, IDOR, eventos alterados, CSRF.
17. Alertas con destinataria y canal.
18. Purga de datos programada.
19. `security.txt` y nota de causa raíz.

---

## 6. Ley 1581 de 2012 / Decreto 1074 de 2015 y normas cercanas

| Obligación | Norma | Cobertura | Estado | Gap |
|---|---|---|---|---|
| Autorización previa, expresa e informada | Ley 1581 art. 9; D. 1074 | FR-049, FR-075 | Cubierto | — |
| Prueba de la autorización | D. 1074 | FR-075 | Cubierto | — |
| Autorización separada para marketing | Ley 1581 art. 4 | FR-049, FR-063 | Cubierto | — |
| Aviso de privacidad | D. 1074 | FR-075 | Cubierto | — |
| Política de tratamiento con contenido mínimo | D. 1074 art. 2.2.2.25.3.1 | FR-074 | Parcial | Criterio de aceptación con el contenido mínimo |
| Derechos: consultas 10 días hábiles (+5), reclamos 15 (+8) | Ley 1581 arts. 8, 14, 15 | FR-083 | Parcial | Fijar plazos, canal y marca "reclamo en trámite" |
| Datos de terceros (destinataria del regalo) | Ley 1581 art. 9 | DC | **Parcial** | FR-048 sin declaración de autorización ni limitación de uso |
| Niñas, niños y adolescentes | Ley 1581 art. 7; D. 1074 | — | **Huérfano** | Mayoría de edad o autorización del representante en términos y checkout |
| Datos sensibles (origen étnico inferido del tono) | Ley 1581 arts. 5–6 | FR-020, FR-081 | Cubierto en P0 | Si en P1 el tono se asocia a una cuenta, reclasificar |
| Encargados: contrato de transmisión | D. 1074 | DC regla 4 | Parcial | Validar términos de cada proveedor con el abogado |
| Transmisiones internacionales | Ley 1581 art. 26 | DC regla 4 | Parcial | Declararlas e identificar países |
| Seguridad de la información | Ley 1581 arts. 4, 17 | TM | Cubierto (sujeto a §5) | — |
| Informar incidentes a la SIC | Ley 1581 arts. 17, 18; Circular Única SIC (plazo por verificar) | — | **Huérfano** | En el plan de incidentes |
| Responsabilidad demostrada | D. 1074 | DC, TM | Parcial | Documento breve del programa |
| RNBD | Ley 1581 art. 25; D. 090 de 2018 | DC regla 6 | Parcial (legal) | Dejar constancia de la decisión |
| Contacto comercial por SMS/WhatsApp | Ley 2300 de 2023 | FR-063 | Parcial | Horarios y frecuencia; validar alcance |
| Retracto y **reversión del pago** | Ley 1480 arts. 47 y 51 | FR-076 | Parcial | Estado y conciliación de reversiones |

---

## Notas para el arquitecto

- Lo que más pesa para cerrar el checkpoint son los ítems 1–7 de §5. Los ítems 1 y 2 piden **verificar** el comportamiento del plugin oficial de Wompi.
- La lista de requisitos del SAQ A en §1 debe cotejarse con el PDF oficial. Confirmado con fuentes externas: eliminación de 6.4.3, 11.6.1 y 12.3.1 en r1 (enero 2025), nuevo criterio sobre scripts y obligación del ASV (11.3.2).
- Números de artículo del Decreto 1074 y el plazo para avisar a la SIC: validar con el abogado (ya es dependencia de apertura).

**Fuentes**: Report URI (PCI DSS 4.0.1 SAQ A FAQ); Schellman (v4.0.1 update for e-commerce merchants; SAQ A ASV scan requirements); usd AG (Update SAQ A 2025); Jscrambler (Changes to SAQ A); PCI SSC blog (e-commerce requirements after 31 March 2025; Resource guide on ASVs); PCI Proxy (Do SAQ A merchants need ASV scans).


---

## 7. Estado tras el plan (iteración 3)

Las tablas §1–§6 reflejan el estado **antes** del plan. Este cuadro indica cómo queda cada hueco con el plan vigente (`plan.md` iteración 3, `research.md` v3).

| Ítem | Estado antes | Estado con el plan | Dónde |
|------|--------------|--------------------|-------|
| E4 AOC de Wompi | Parcial | Cubierto (puerta fase 1) | R-19 |
| E5 Tarjetas por otros canales / 9.4 | Huérfano | Cubierto | FR-083a, R-19 |
| E6 Modo del plugin | Por verificar | Verificado: widget; evidencia de iframe como puerta fase 1 | R-01, R-19 |
| E7 / 6.4.3 / 11.6.1 Scripts | Parcial | Cubierto | R-11 |
| 2.2.2 Cuentas por defecto | Parcial | Cubierto (sin `admin`, credenciales propias) | R-19 |
| 6.3.1 / 6.3.3 Vulnerabilidades y parcheo | Parcial | Cubierto (Wordfence, SLA ≤ 7 días, emergencia) | R-15 |
| 8.2.x / 8.2.5 / 8.3.x Cuentas | Parcial / Huérfano | Cubierto (cuenta temporal con baja, 2FA y ≥ 12 en todas) | FR-078, R-19 |
| 11.3.2 ASV | Huérfano | Cubierto (puerta fase 5; trimestral y tras cambios) | R-19 |
| 12.8.x TPSP | Parcial | Cubierto (lista, matriz, AOC) | R-19 |
| 12.10.1 Incidentes | Huérfano | Cubierto (plan con SIC y causa raíz) | R-19 |
| 6.2.3 Revisión de código | Parcial | Cubierto (gates de logidev + revisora humana nombrada antes y después de abrir) | R-15 |
| 6.5.6 Salida a producción | Parcial | Cubierto | `ops/go-live-checklist.md` |
| PS.1.1 Control de versiones | Parcial | Cubierto (GitHub privado con 2FA) | R-15 |
| ISO 5.18 Derechos de acceso | Huérfano | Cubierto (`ops/runbooks/access.md`, revisión semestral) | R-19 |
| ISO 5.24–5.27 Incidentes | Huérfano | Cubierto | R-19 |
| ISO 8.13 Copias | Parcial | Cubierto (externa cifrada, RPO/RTO, restauración trimestral) | R-17 |
| ISO 8.16 Monitoreo | Parcial | Cubierto (matriz de alertas) | R-22 |
| ISO 8.10 Eliminación | Parcial | Cubierto (purgas) | R-23 |
| Ley 1581: niñas, niños y adolescentes | Huérfano | Cubierto | FR-049 |
| Ley 1581: datos de quien recibe un regalo | Parcial | Cubierto | FR-048, R-23 |
| Ley 1581: aviso de incidentes a la SIC | Huérfano | Cubierto | FR-083b, R-19 |
| Ley 1480: reversión del pago | Parcial | Cubierto | FR-058a, data-model §3 |
| Pendientes legales (contratos de transmisión, RNBD, Ley 2300, efecto de "Entregado" automático sobre el retracto) | Parcial | Dependencia del abogado antes de la fase 5 | R-19 |
