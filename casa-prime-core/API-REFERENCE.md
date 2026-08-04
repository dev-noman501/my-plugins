# Casa Prime — REST API Reference

**Base URL:** `https://app.loomandlure.com/wp-json/casa-prime/v1`

**Auth:** endpoints marked 🔒 need the JWT in a header: `Authorization: Bearer <token>` (token milta hai login/register se).
All responses are JSON, wrapped as `{ "success": true, ... }`; errors return `{ "code", "message", "data": { "status" } }`.

---

## 1. Auth

| # | Method | Endpoint | Auth | Body |
|---|--------|----------|------|------|
| 1 | POST | `/auth/register` | public | `{ "name", "email", "phone", "password" }` → JWT + user |
| 2 | POST | `/auth/login` | public | `{ "login": "<email ya phone>", "password" }` → JWT + user |
| 3 | GET | `/auth/me` | 🔒 | — (current user profile, `has_address`, `onboarding_complete`) |
| 4 | POST | `/auth/phone-verified` | 🔒 | app Firebase Phone Auth ke baad flag set karti hai |
| 5 | POST | `/auth/logout-all` | 🔒 | — (sab purane tokens invalidate) |

## 2. Password

| # | Method | Endpoint | Auth | Body |
|---|--------|----------|------|------|
| 6 | POST | `/auth/forgot-password` | public | `{ "login" }` → 6-digit code email hota hai |
| 7 | POST | `/auth/verify-reset-code` | public | `{ "login", "code" }` → sirf code check |
| 8 | POST | `/auth/reset-password` | public | `{ "login", "code", "password" }` → naya password + fresh token |
| 9 | POST | `/auth/change-password` | 🔒 | `{ "current_password", "password" }` |

Code 15 minute mein expire, one-time use.

## 3. Products & Store (public — guest browse)

| # | Method | Endpoint | Notes |
|---|--------|----------|-------|
| 10 | GET | `/products` | query: `category`, `search`, `featured`, `page`, `per_page`. Offer wale product par `on_offer`, `offer_price`, `regular_price_display`, `offer_ends_at` |
| 11 | GET | `/products/{id}` | detail + `images`, `cut_options`, weight fields (`weight_step`/`min_weight`/`max_weight`), `price_for_min_weight` |
| 12 | GET | `/categories` | Beef, Chicken, Pork, Seafood, Deli, Grocery + images |
| 13 | GET | `/special-offer` | home banner: `active`, `headline`, `offer_price`, `regular_price`, `image`, `ends_at`, `seconds_remaining` (countdown) |
| 14 | GET | `/store/contact` | store ka address/phone/hours |

## 4. Delivery (public)

| # | Method | Endpoint | Notes |
|---|--------|----------|-------|
| 15 | GET | `/delivery/quote?lat=..&lng=..&subtotal=..` | `deliverable`, `fee`, `distance` — 5-mile radius = free, bahar = no delivery |
| 16 | GET | `/delivery/dates` | order ke liye available days |

## 5. Addresses 🔒

| # | Method | Endpoint | Body |
|---|--------|----------|------|
| 17 | GET | `/addresses` | — |
| 18 | POST | `/addresses` | `{ "label": "Home/Work", "address_1"*, "apt", "city", "state", "postcode", "notes", "lat", "lng", "is_default" }` → address + delivery quote |
| 19 | PUT | `/addresses/{id}` | same fields |
| 20 | DELETE | `/addresses/{id}` | — |
| 21 | POST | `/addresses/{id}/default` | default set karo |

## 6. Favorites 🔒

| # | Method | Endpoint |
|---|--------|----------|
| 22 | GET | `/favorites` (full product objects) |
| 23 | POST | `/favorites/{product_id}` |
| 24 | DELETE | `/favorites/{product_id}` |

## 7. Cart 🔒 (server-side cart, prices hamesha server par calculate)

| # | Method | Endpoint | Body |
|---|--------|----------|------|
| 25 | GET | `/cart` | — (items + totals) |
| 26 | POST | `/cart/items` | `{ "product_id", "amount", "cut", "instructions" }` — weight product mein `amount` = lb, each mein qty |
| 27 | PUT | `/cart/items/{key}` | `{ "amount", "cut", "instructions" }` (koi bhi field) |
| 28 | DELETE | `/cart/items/{key}` | — |
| 29 | DELETE | `/cart` | poora cart khali |
| 29a | POST | `/cart/coupon` | `{ "code": "SAVE10" }` coupon apply; response cart mein `coupon`, `discount`, `total` |
| 29b | DELETE | `/cart/coupon` | applied coupon remove |

### Coupon integration

Apply:
```http
POST /wp-json/casa-prime/v1/cart/coupon
Authorization: Bearer <JWT>
Content-Type: application/json

{"code":"SAVE10"}
```

Remove:
```http
DELETE /wp-json/casa-prime/v1/cart/coupon
Authorization: Bearer <JWT>
```

Apply/remove dono updated `cart` return karte hain:

- `subtotal`: coupon se pehle product total
- `coupon`: applied coupon detail, warna `null`
- `discount`: coupon saving
- `total`: `subtotal - discount`; delivery/tip checkout par add honge
- `*_display`: currency-formatted value

Server coupon apply aur checkout dono par expiry, usage limits, per-user limit,
email, min/max spend, product/category aur sale-item restrictions validate karta
hai. Client sirf returned totals display kare; local discount calculation na kare.

## 8. Checkout & Orders 🔒

| # | Method | Endpoint | Body |
|---|--------|----------|------|
| 30 | POST | `/checkout` | `{ "fulfillment": "delivery"\|"pickup", "payment_method": "cod"\|"card", "address_id", "delivery_date": "today"\|"YYYY-MM-DD", "tip", "note" }` |
| 31 | GET | `/orders` | order history (items + `items_summary` ke sath) |
| 32 | GET | `/orders/{id}` | order detail |
| 33 | POST | `/orders/{id}/confirm-payment` | card payment ke baad app call kare → order paid + processing |
| 34 | GET | `/orders/{id}/track` | live status; out-for-delivery mein rider ki live location (app har 10–15s poll kare) |

Card flow: checkout → `needs_payment: true` → app payment kare → `confirm-payment`. COD seedha `processing`.

### Stripe card payment (naya)

| # | Method | Endpoint | Notes |
|---|--------|----------|-------|
| 33b | GET | `/config` | public — `payments.card` (on/off), `stripe.publishable_key`, currency. App boot par yeh call karo |
| 33c | POST | `/orders/{id}/create-payment-intent` | 🔒 pending card order → `{client_secret, publishable_key, amount}` — isi se Stripe PaymentSheet kholo. Dobara call = same intent (jab tak amount match) |
| 33d | POST | `/stripe/webhook` | Stripe ke liye (signature-verified) — app crash ho jaye to bhi order paid ho jata hai |

Card flow (full): checkout → create-payment-intent → PaymentSheet (device par) → confirm-payment (server Stripe se VERIFY karta hai — status succeeded + amount match, warna 402). Keys wp-config mein na hon to `payments.card: false` aur confirm-payment purane test-mode (bina verification) mein chalta hai. Failed-delivery cancel par prepaid orders ka full Stripe refund automatic hai.

## 9. Push Notifications 🔒

| # | Method | Endpoint | Body |
|---|--------|----------|------|
| 35 | POST | `/device-token` | `{ "token": "<FCM token>", "platform": "android"\|"ios" }` — multi-device, dedup auto |
| 36 | DELETE | `/device-token` | `{ "token" }` — logout par |
| 36b | GET | `/device-token` | — (debug: mere registered devices + `device_count`) |

Push aati hai: order confirmed / rider arrived / out for delivery / delivered (customer), new order assigned + redelivery assigned (rider). Payload data: `{ "type": "order", "order_id": "58" }`.

## 10. Rider App 🔒 (rider role)

| # | Method | Endpoint | Body / Notes |
|---|--------|----------|--------------|
| 37 | POST | `/rider/location` | `{ "lat", "lng" }` — har 10–15s ping |
| 38 | POST | `/rider/availability` | `{ "status": "available"\|"offline" }` |
| 39 | GET | `/rider/orders` | mere assigned orders (ready + out-for-delivery) |
| 40 | GET | `/rider/orders/pickup` | pickup tab — store par waiting |
| 41 | GET | `/rider/orders/deliver` | deliver tab — raste mein |
| 42 | POST | `/rider/orders/{id}/pickup` | picked up (ready → out-for-delivery) |
| 42b | POST | `/rider/orders/{id}/arrived` | "I've Arrived" — customer ko push "rider at your door" |
| 42c | POST | `/rider/orders/{id}/collect-cash` | COD screen — cash haath mein aa gaya (prepaid par 400) |
| 42d | POST | `/rider/orders/{id}/proof` | proof of delivery: multipart `file` YA base64 `image` + `method` ("photo"\|"signature") — customer + manager dono ko dikhta hai |
| 42e | POST | `/rider/orders/{id}/failed` | `{ "reason" }` (required) → status `failed-delivery`, manager ki Failed lane mein |
| 43 | POST | `/rider/orders/{id}/delivered` | delivered — tip + COD earnings mein land hota hai |
| 44 | GET / POST | `/rider/current-delivery` | POST `{ "order_id" }` (0 = clear) — abhi kis order ki taraf ja raha hoon |
| 45 | GET | `/rider/earnings?from=YYYY-MM-DD&to=YYYY-MM-DD` | day-grouped record: tips, COD collected, deliveries + `failed_deliveries` count (End of Day screen) |
| 45b | GET | `/rider/history?from=&to=` | Delivery History: delivered + failed orders, proof thumbnail, COD/tip, fail reason |

Rider orders list mein ab har order ke sath flow-state bhi: `arrived_at`, `cash_collected`, `proof` — app reopen par jahan chhora tha wahan se continue.
Order flow per delivery: pickup → arrived → (COD ho to collect-cash) → proof → delivered. Ya failed ho to `failed` with reason.
Failed order manager panel ki **Failed lane** mein jata hai: manager "Send again" (dobara rider assign — rider ko push jati hai) ya "Cancel order" (prepaid ho to refund manually) kar sakta hai.

## 11. Manager 🔒 (manager/admin role)

| # | Method | Endpoint | Body / Notes |
|---|--------|----------|--------------|
| 46 | GET | `/riders` | sab riders + live location + `active_orders[]` + `active_order_count` |
| 47 | GET | `/riders/{id}/balance` | COD pending, tips, settlements |
| 48 | POST | `/riders/{id}/settle` | `{ "amount", "note" }` — rider se cash receive record |

---

### Order statuses (app ke liye)
`processing` (Placed) → `preparing` (Confirmed/Accept) → `ready` → `out-for-delivery` → `delivered`.
Side: `rejected`, `cancelled`, `failed`. **Note:** `confirmed` status khatam ho chuka hai — ab seedha `preparing`.

### Pricing model
Exact charge — `total = weight × per-lb price`, koi estimate/adjustment nahi (`exact_charge: true` har product par). Special offer active ho to `price` khud offer wali ho jati hai (product, cart, checkout sab jagah same).
