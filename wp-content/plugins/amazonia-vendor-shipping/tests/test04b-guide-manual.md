# Test Fase 4B — Verificación manual del botón de guía (UI)

Requiere navegar el dashboard del vendedor, por eso es manual.

## Preparación
- Cuenta de Envia conectada (WooCommerce → Envia.com muestra el store conectado).
- Dos vendedores (A y B) con al menos un pedido cada uno que incluya un método de Envia.

## Casos

### A. El vendedor ve la guía de su pedido
1. Inicia sesión como **vendedor A**.
2. Dashboard → **Pedidos** → abre un pedido propio.
3. [ ] En las acciones rápidas aparece el botón **"Generar / Ver guía de envío"**.
4. [ ] Al hacer clic, se abre el modal con el panel de Envia cargando **ese** pedido (`id=<order>`).
5. [ ] Desde ahí se puede generar y descargar la guía (PDF) del pedido.
6. [ ] El botón cierra con la ✕, el fondo, o la tecla Esc.

### B. Aislamiento entre vendedores
1. Como **vendedor A**, copia la URL de detalle de un pedido de A y sustituye el ID por uno del **vendedor B**.
2. [ ] WCFM muestra **"Restricted Order"** y **no** aparece el botón de guía.

### C. Sin conexión de Envia
1. (Opcional) Con Envia desconectado, abrir un pedido.
2. [ ] El botón **no** se muestra (evita abrir un panel inválido).

### D. Pedido que no usó Envia (p. ej. Recogida local)
1. Abrir un pedido cuyo método de envío sea **Recogida local** u otro que no sea Envia.
2. [ ] El botón **no** se muestra (la guía de Envia no aplica a ese pedido).

## Nota de seguridad (MVP)
El panel usa la cuenta de Envia del marketplace (hash compartido). Pasamos solo el `id` del
pedido del vendedor; aun así, dentro del panel de Envia la sesión es de la cuenta central.
La Fase 6 (API directa) elimina esta exposición guardando el PDF/tracking en el propio pedido.
