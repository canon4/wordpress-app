# Test Fase 8B — Transportadora por vendedor (verificación en checkout)

Requiere Envia con la tienda cotizando (servicios habilitados en el panel Ecommerce Pro).

## Modelo
Cada vendedor fija **UNA** transportadora (la que tenga oficina de recogida cerca). El cliente
solo ve esa. Si el vendedor no elige, se usa la **lista por defecto del marketplace**
(WooCommerce → Envíos Amazonia → "Transportadoras por defecto"). Lista por defecto vacía = todas.

## A. Vendedor fija su transportadora
1. Dashboard del vendedor → Ajustes → Envío → **"Transportadora de tu tienda"** → elige p. ej. **Servientrega**. Guarda.
2. En el checkout, con un producto de ese vendedor:
   - [ ] Solo aparecen tarifas de **Servientrega** (Premier/Industrial), ninguna de otra transportadora.
   - [ ] Se aplica el modo de cobro del vendedor (cliente paga / gratis / fija) sobre esas tarifas.

## B. Lista por defecto del marketplace
1. En el dashboard del vendedor, deja **"Usar las del marketplace (por defecto)"**.
2. WooCommerce → **Envíos Amazonia** → marca solo **Coordinadora** en "Transportadoras por defecto". Guarda.
3. En el checkout con un producto de ese vendedor:
   - [ ] Solo aparecen tarifas de **Coordinadora**.

## C. Sin restricción
1. En "Envíos Amazonia" desmarca todas las "Transportadoras por defecto" y deja el vendedor sin transportadora.
2. En el checkout:
   - [ ] Aparecen **todas** las transportadoras que devuelve Envia.

## Notas
- El filtro es a nivel **transportadora** (carrier), no de servicio: si la transportadora tiene
  varios servicios (Servientrega Premier y Industrial), el cliente verá ambos (misma oficina de
  recogida). Si se quiere mostrar un solo precio, se puede colapsar al más económico (cambio menor).
- WooCommerce cachea las tarifas por sesión/carrito. Si cambias la config de transportadora con un
  carrito ya cotizado abierto, puede requerir cambiar el carrito/destino para refrescar.
- El envío nativo de WCFM del vendedor ("Envío gratuito"/by_country) es **independiente** de Envia
  y sigue apareciendo si está activo; si compite indebidamente, revisar la config de envío del
  vendedor en WCFM.
