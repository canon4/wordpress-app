# Test Fase 7B — Origen por vendedor (verificación viva)

Requiere red y la cuenta de Envia conectada, por eso es manual.

## Preparación
- WooCommerce → Envia.com conectado (token válido).
- Al menos dos vendedores con dirección de tienda completa (calle, ciudad, estado, país; CP si el país lo exige).

## A. Registro automático al guardar la tienda
1. Como **vendedor A**, ve al dashboard → Ajustes → Envío (o Tienda) y **guarda** con la dirección completa.
2. [ ] En `user_meta` del vendedor aparece `_avs_envia_origin_id` con un ID numérico de Envia.
3. [ ] En el panel de Envia (shipping.envia.com) esa dirección figura como dirección de origen.

## B. Botón de respaldo "Sincronizar con Envia"
1. Como **vendedor A**, en cualquier página del dashboard aparece abajo a la derecha la caja **"Origen de envío (Envia)"**.
2. [ ] Muestra el estado correcto (sincronizado + ID, o "aún no sincronizado", o "completa tu dirección").
3. [ ] Al pulsar **"Sincronizar con Envia"** el estado cambia a "Origen sincronizado con Envia correctamente." y se guarda/actualiza el `address_id`.
4. [ ] Si la dirección está incompleta, el botón aparece deshabilitado con el aviso correspondiente.

## C. Cotización con el origen del vendedor (el bug original)
1. Carrito con un producto del **vendedor A** (origen ya sincronizado).
2. Checkout con una dirección de destino válida (p. ej. Colombia, Florencia).
3. [ ] Aparece **al menos una tarifa de Envia** además de "Recogida local" (ya hay opción de elegir).
4. [ ] La tarifa refleja el modo de cobro del vendedor (cliente paga / gratis / fija) igual que en las fases previas.
5. [ ] **No** aparece el aviso "Enter a valid address to view shipping options".

## D. Origen por vendedor (aislamiento)
1. Carrito con productos de **dos vendedores** con orígenes distintos (A en ciudad X, B en ciudad Y).
2. [ ] Cada paquete de vendedor cotiza desde SU propio origen (los costos difieren si las distancias difieren).

## E. Bloqueo cuando falta el origen (decisión: bloquear con aviso claro)
1. Vendedor **C** con dirección incompleta o sin sincronizar, y **sin** recogida local disponible para su paquete.
2. Añade un producto de C al carrito e intenta finalizar la compra.
3. [ ] El checkout se **bloquea** con el aviso: *La tienda "…" aún no ha configurado su dirección de origen de envío…*.
4. [ ] Si C **sí** tiene recogida local u otra tarifa disponible, NO se bloquea (se permite esa alternativa).

## Notas
- La cotización reutiliza el motor real de Envia forzando el origen del vendedor; hoy hereda una
  limitación del plugin de Envia: el **peso/dimensiones** se toman del carrito completo, no solo del
  paquete del vendedor. Con un vendedor por pedido es exacto; con carritos multivendedor puede
  sobrestimar. Afinar (peso por paquete) queda para una iteración posterior.
- Solo se inyectan tarifas de **entrega a domicilio** (dropOff 0/1). Las de **recogida en sucursal**
  (dropOff 2/3) no se replican aquí todavía.
