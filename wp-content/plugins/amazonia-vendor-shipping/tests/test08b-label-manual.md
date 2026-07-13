# Test Fase 6B — Generación de guía por API (verificación viva)

Requiere: **API key de Envia** configurada y **saldo** en la cuenta (generar guía cuesta dinero real).

## Preparación (una sola vez)
1. En el panel de Envia → **Integraciones / API** → genera una **API key / token de API**.
2. WooCommerce → **Envíos Amazonia** → pega la key en **"API Key de Envia (guías)"** → debe verse **✓ Configurada**.
3. **Entorno**: si la API key es de una **cuenta de pruebas**, marca **"Usar entorno de pruebas (sandbox)"**
   (usa `api-test.envia.com`). Con key de producción, déjalo desmarcado (`api.envia.com`). Un token de
   test da error de autenticación contra el host de producción y viceversa.
4. Asegúrate de tener saldo en la cuenta de Envia (o postpago habilitado).

## A. El vendedor genera la guía (sin login de Envia)
1. Haz una compra de prueba que use un envío de **Envia** (no recogida local) de un vendedor con origen sincronizado, y márcala pagada.
2. Inicia sesión como **ese vendedor** → Dashboard → Pedidos → abre el pedido.
3. [ ] En las acciones rápidas aparece **"Generar guía"** (ya NO abre un iframe que pide login).
4. Da clic → confirma el aviso ("crea un envío real y consume saldo").
   - [ ] Al terminar, el botón cambia a **"Descargar guía (PDF)"** y muestra el **Tracking**.
   - [ ] Al hacer clic en "Descargar guía (PDF)" se abre/descarga el PDF, **sin pedir iniciar sesión en Envia**.
5. [ ] El origen de la guía es la **dirección del vendedor** (no un origen central).
6. [ ] El pedido registra una nota con el tracking.

## B. Errores claros (sin romper)
1. Sin API key configurada:
   - [ ] "Generar guía" responde con un mensaje claro: *Falta configurar la API key de Envia…* (no genera nada).
2. Vendedor sin origen en Envia:
   - [ ] Mensaje claro: *El vendedor de este pedido no tiene un origen configurado…*.

## C. Aislamiento entre vendedores
1. Como vendedor A, intenta generar/descargar la guía de un pedido de vendedor B (manipulando el order_id).
2. [ ] La API REST lo rechaza (permiso denegado). Solo el dueño (o el admin) puede generar/descargar.

## D. Idempotencia
1. Con una guía ya generada:
   - [ ] El botón muestra directamente "Descargar guía (PDF)" (no vuelve a cobrar/generar).

## Notas
- El PDF se sirve desde la URL que devuelve Envia (S3), guardada en el pedido (`_avs_label_url`).
- Si necesitas **cancelar** una guía (para no perder el saldo), hoy se hace desde el panel de Envia;
  una acción de cancelar por API se puede agregar después (endpoint ship/cancel).
- Verifica el contrato exacto de `api.envia.com/ship/generate` con la primera guía real: si Envia
  pide algún campo adicional por país/carrier, se ajusta el payload (igual que se hizo con user-address).
