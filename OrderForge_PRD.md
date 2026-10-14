# OrderForge
## Distributed Order & Inventory Management System
### Product Requirements Document (PRD)

> **Project type:** Portfolio / Dummy Production-Grade System
> **Primary target role:** Backend Developer / Software Engineer
> **Primary focus:** Reliability, concurrency, event-driven architecture, transactions, observability, and production engineering
> **Frontend priority:** Secondary. Keep UI clean and functional, but backend quality is the main portfolio signal.

---

# 1. Executive Summary

**OrderForge** is a production-style order and inventory management platform designed to demonstrate backend engineering beyond basic CRUD.

The system simulates a real commerce backend where multiple customers can order limited stock concurrently, payments may arrive late or be retried, webhooks may be duplicated, background jobs may fail, and services must remain consistent.

The core engineering challenge is:

> **How do we guarantee that orders, inventory, and payments remain correct under concurrency, retries, partial failures, and asynchronous processing?**

OrderForge must show practical understanding of:

- ACID transactions
- Race conditions
- Database locking
- Idempotency
- Event-driven architecture
- Transactional Outbox
- Queues and background jobs
- Retry and dead-letter handling
- Redis caching
- Rate limiting
- Webhooks
- Realtime updates
- Structured logging
- Metrics and health checks
- Automated testing
- Dockerized development
- CI/CD
- Clean architecture and SOLID principles

The project should feel like a small but serious production system rather than a CRUD demo.

---

# 2. Portfolio Objective

OrderForge exists to complement **OpsFlow**.

## OpsFlow demonstrates

- Enterprise business workflow
- Service desk
- Asset management
- Approval flow
- RBAC
- SLA
- Audit logs
- REST API
- Redis / queues
- Docker
- Automated testing

## OrderForge should demonstrate

- High-integrity transactional backend
- Concurrency handling
- Inventory consistency
- Payment lifecycle
- Idempotent API design
- Event-driven architecture
- Async workflows
- Failure recovery
- Observability
- Realtime updates
- Production-oriented backend design

Together:

```text
OpsFlow
└── Enterprise Application Engineering

OrderForge
└── Distributed / Transactional Backend Engineering
```

---

# 3. Product Vision

Build a mini commerce infrastructure platform where:

1. Products exist in one or more warehouses.
2. Customers create orders.
3. Inventory is reserved atomically.
4. Customers complete a mocked payment.
5. Payment confirmation is received through a webhook.
6. Successful payments commit the order.
7. Unpaid orders expire automatically.
8. Expired orders release reserved stock.
9. Duplicate requests never create duplicate side effects.
10. Concurrent purchases never make stock negative.
11. Important state changes are published as domain events.
12. Admins can monitor orders, payments, inventory, failures, and event history.

---

# 4. Goals

## 4.1 Primary Goals

- Guarantee inventory correctness under concurrent purchases.
- Prevent duplicate orders and duplicate payment processing.
- Demonstrate safe asynchronous processing.
- Demonstrate reliable event publication.
- Provide traceable state changes through logs and audit events.
- Provide automated test coverage for business-critical behavior.
- Make the whole project runnable locally through Docker Compose.
- Make architecture explainable during technical interviews.

## 4.2 Secondary Goals

- Provide a simple admin dashboard.
- Provide realtime order status updates.
- Provide basic performance metrics.
- Demonstrate clean, maintainable Laravel architecture.

---

# 5. Non-Goals

The following are explicitly out of scope for the first production-ready portfolio version:

- Real payment provider integration
- Real shipping provider integration
- Multi-region deployment
- Kubernetes
- Full microservice decomposition
- Complex marketplace seller model
- Advanced tax engine
- Promotions / vouchers
- Recommendation engine
- Customer chat
- Full accounting system
- Mobile application

These may be added later only if they improve the portfolio story.

---

# 6. Target Users

## 6.1 Customer

Can:

- View products
- View available stock
- Create an order
- Check order status
- Trigger mocked payment
- View payment result
- Receive realtime order status updates

## 6.2 Operations Admin

Can:

- View all orders
- View inventory
- View stock reservations
- View payments
- View failed jobs
- View dead-letter events
- Inspect order event history
- Retry recoverable failures
- Adjust stock with audit reason

## 6.3 System / Worker

Responsible for:

- Expiring unpaid orders
- Releasing reserved inventory
- Publishing outbox events
- Sending notifications
- Processing payment events
- Retrying failed asynchronous operations

---

# 7. Recommended Technology Stack

## Backend

- PHP 8.4+
- Laravel
- PostgreSQL
- Redis
- RabbitMQ
- Laravel Queue
- Laravel Reverb or compatible WebSocket mechanism
- OpenAPI / Swagger documentation

## Frontend

Recommended:

- Next.js
- TypeScript
- React
- Tailwind CSS

Alternative:

- Laravel Blade + Alpine.js

Prefer Next.js only if it does not distract from backend development.

## Infrastructure

- Docker
- Docker Compose
- Nginx
- GitHub Actions

## Observability

Minimum:

- Structured JSON logging
- Request / correlation ID
- Queue job logging
- Health endpoints

Recommended:

- Prometheus
- Grafana

Optional later:

- OpenTelemetry

---

# 8. Architectural Decision

## 8.1 Architecture Style

Start as a **modular monolith**.

Do not prematurely split into real microservices.

The application should be divided into clear bounded modules:

```text
OrderForge
├── Identity
├── Catalog
├── Inventory
├── Orders
├── Payments
├── Notifications
├── Audit
└── Shared
```

Each module should have clear ownership of its domain logic.

The system may use events internally in a way that makes future service extraction possible.

---

# 9. High-Level Architecture

```text
                      ┌────────────────────┐
                      │     Next.js UI     │
                      └─────────┬──────────┘
                                │
                                ▼
                     ┌──────────────────────┐
                     │   Laravel REST API   │
                     └──────────┬───────────┘
                                │
          ┌─────────────────────┼─────────────────────┐
          │                     │                     │
          ▼                     ▼                     ▼
   ┌─────────────┐      ┌──────────────┐      ┌─────────────┐
   │ Order Module│      │Inventory Mod.│      │Payment Mod. │
   └──────┬──────┘      └──────┬───────┘      └──────┬──────┘
          │                     │                     │
          └──────────────┬──────┴──────────────┬──────┘
                         ▼                     ▼
                  ┌─────────────┐       ┌────────────┐
                  │ PostgreSQL  │       │   Redis    │
                  └──────┬──────┘       └────────────┘
                         │
                         ▼
                ┌───────────────────┐
                │ Transactional     │
                │ Outbox            │
                └─────────┬─────────┘
                          ▼
                    ┌────────────┐
                    │ RabbitMQ   │
                    └─────┬──────┘
                          │
             ┌────────────┼────────────┐
             ▼            ▼            ▼
       Notifications   Workers     Realtime
```

---

# 10. Core Domain Concepts

## Product

Represents an item that can be ordered.

Important fields:

```text
id
sku
name
description
price
status
created_at
updated_at
```

## Warehouse

```text
id
code
name
location
status
created_at
updated_at
```

## Inventory

Represents stock for a product in a warehouse.

```text
id
product_id
warehouse_id
on_hand
reserved
version
created_at
updated_at
```

Calculated:

```text
available = on_hand - reserved
```

Invariant:

```text
on_hand >= 0
reserved >= 0
reserved <= on_hand
available >= 0
```

## Order

```text
id
order_number
customer_id
status
subtotal
total
currency
expires_at
paid_at
cancelled_at
created_at
updated_at
```

## Order Item

```text
id
order_id
product_id
sku_snapshot
name_snapshot
price_snapshot
quantity
subtotal
```

## Stock Reservation

```text
id
order_id
product_id
warehouse_id
quantity
status
expires_at
released_at
created_at
updated_at
```

## Payment

```text
id
order_id
payment_reference
provider
amount
currency
status
paid_at
failed_at
created_at
updated_at
```

## Payment Webhook

Stores webhook deliveries to guarantee idempotent processing.

```text
id
provider
external_event_id
payload
processed_at
status
created_at
```

## Outbox Event

```text
id
event_id
aggregate_type
aggregate_id
event_type
payload
status
attempts
available_at
published_at
created_at
```

## Audit Log

```text
id
actor_type
actor_id
action
entity_type
entity_id
metadata
request_id
created_at
```

---

# 11. Order State Machine

Allowed states:

```text
PENDING_PAYMENT
PAID
PROCESSING
COMPLETED
CANCELLED
EXPIRED
```

Transitions:

```text
PENDING_PAYMENT
├── payment confirmed ──> PAID
├── customer cancel ────> CANCELLED
└── timeout ────────────> EXPIRED

PAID
└── processing starts ──> PROCESSING

PROCESSING
└── fulfillment done ───> COMPLETED
```

Rules:

- PAID orders cannot expire.
- COMPLETED orders are immutable.
- EXPIRED orders cannot be paid.
- CANCELLED orders cannot be paid.
- Invalid transitions must return a domain error.
- State changes must be persisted transactionally.

Use a dedicated state transition service or state machine abstraction.

---

# 12. Payment State Machine

States:

```text
PENDING
PROCESSING
SUCCEEDED
FAILED
EXPIRED
REFUNDED
```

Important invariant:

A successful provider event may be delivered multiple times but must only transition the payment once.

---

# 13. Reservation State Machine

States:

```text
ACTIVE
COMMITTED
RELEASED
EXPIRED
```

Meaning:

- ACTIVE: inventory temporarily reserved.
- COMMITTED: payment succeeded and inventory is permanently consumed.
- RELEASED: reservation manually cancelled.
- EXPIRED: reservation timed out.

---

# 14. Critical Business Flow: Create Order

## Request

```http
POST /api/v1/orders
Idempotency-Key: 8fd7...
```

Example payload:

```json
{
  "items": [
    {
      "product_id": 10,
      "quantity": 2
    }
  ]
}
```

## Required behavior

1. Validate idempotency key.
2. Validate products.
3. Begin PostgreSQL transaction.
4. Lock required inventory rows.
5. Validate available quantity.
6. Create order.
7. Create order items using product snapshots.
8. Increase inventory reserved amount.
9. Create stock reservations.
10. Create outbox event.
11. Commit transaction.
12. Return order.

If any inventory is insufficient:

- rollback entire transaction
- no partial reservation is allowed

---

# 15. Concurrency Strategy

This is one of the most important portfolio features.

Scenario:

```text
Available stock = 1

Customer A buys 1
Customer B buys 1
```

Both requests may arrive at nearly the same time.

Only one order may reserve the stock.

## Required implementation

Use PostgreSQL transaction and row-level locking.

Conceptually:

```sql
SELECT *
FROM inventories
WHERE product_id = ?
FOR UPDATE;
```

Inside the transaction:

```text
available = on_hand - reserved

if available < requested:
    reject

reserved += requested
```

Do not rely only on application-level checks.

Do not use Redis lock as the primary inventory correctness mechanism.

PostgreSQL remains the source of truth.

---

# 16. Optimistic Versioning

Inventory table should also include:

```text
version
```

This can be used for experimentation with optimistic concurrency.

However, the primary MVP strategy should remain row-level locking because it is easier to reason about and demonstrate correctly.

The README should explain the tradeoff between:

- pessimistic locking
- optimistic locking

---

# 17. Idempotency

The system must support idempotent write operations.

Required endpoints:

```text
POST /orders
POST /payments/mock
POST /admin/inventory/adjustments
```

Header:

```http
Idempotency-Key: <uuid>
```

Create table:

```text
idempotency_keys

id
key
user_id
request_hash
response_status
response_body
expires_at
created_at
```

Rules:

- Same key + same request returns the original response.
- Same key + different payload returns `409 Conflict`.
- Keys expire after a configured period.
- In-progress duplicate requests must not execute business logic twice.

---

# 18. Mock Payment Flow

OrderForge must not depend on a real payment gateway.

Create an internal mocked provider.

## Flow

```text
Order created
     │
     ▼
Payment created
     │
     ▼
User clicks "Pay"
     │
     ▼
Mock Payment Provider
     │
     ▼
Webhook POST /payments/webhook/mock
     │
     ▼
Payment processing
     │
     ▼
Order marked PAID
     │
     ▼
Inventory reservation COMMITTED
```

The mock provider should support:

```text
success
failed
delayed
duplicate webhook
timeout
```

This is useful for demonstrating failure handling.

---

# 19. Webhook Security

Mock webhook must still demonstrate real-world security concepts.

Use:

```text
X-Webhook-Signature
X-Webhook-Timestamp
```

Generate signature using HMAC SHA-256.

Validate:

- signature
- timestamp tolerance
- event uniqueness

Reject invalid signatures.

---

# 20. Duplicate Webhook Handling

Webhook delivery may happen multiple times.

Example:

```text
payment.succeeded
event_id = evt_123
```

Provider sends event 3 times.

Expected:

```text
Delivery 1 → processed
Delivery 2 → acknowledged, ignored
Delivery 3 → acknowledged, ignored
```

Payment and order state must transition only once.

Use:

```text
external_event_id UNIQUE
```

and transaction protection.

---

# 21. Transactional Outbox Pattern

Critical domain events must never depend on:

```text
DB commit
then
publish message
```

because the application may crash between these operations.

Instead:

Inside the same database transaction:

```text
1. update domain state
2. insert outbox event
3. commit
```

Separate worker:

```text
outbox table
    │
    ▼
publisher worker
    │
    ▼
RabbitMQ
```

Event status:

```text
PENDING
PROCESSING
PUBLISHED
FAILED
```

---

# 22. Domain Events

Minimum events:

```text
order.created
order.cancelled
order.expired
order.paid

inventory.reserved
inventory.released
inventory.committed

payment.created
payment.succeeded
payment.failed
```

Each event should contain:

```json
{
  "event_id": "uuid",
  "event_type": "order.created",
  "occurred_at": "...",
  "aggregate_id": "...",
  "request_id": "...",
  "payload": {}
}
```

---

# 23. RabbitMQ

Use RabbitMQ because:

- simpler for a portfolio project
- clear queue semantics
- retry and DLQ are easy to demonstrate
- does not introduce Kafka operational complexity unnecessarily

Kafka may be discussed in architecture documentation as an alternative for high-volume event streaming, but should not be added unless a real requirement appears.

---

# 24. Retry Strategy

For recoverable asynchronous failures:

```text
Attempt 1
↓
30 seconds

Attempt 2
↓
2 minutes

Attempt 3
↓
10 minutes

Attempt 4
↓
Dead Letter Queue
```

Use exponential backoff where practical.

Non-retryable errors should go directly to failure handling.

---

# 25. Dead Letter Queue

Messages that exceed retry limits should enter a DLQ.

Admin should be able to:

- inspect failed message
- see failure reason
- see number of attempts
- manually retry
- mark as discarded

Never silently delete failed business events.

---

# 26. Order Expiration

Default payment expiration:

```text
15 minutes
```

A scheduled job checks expired unpaid orders.

Flow:

```text
PENDING_PAYMENT
   │
expires_at < now
   │
   ▼
EXPIRED
   │
   ▼
release reservation
   │
   ▼
inventory reserved decreases
```

The operation must be idempotent.

---

# 27. Inventory Commitment

When payment succeeds:

Before:

```text
on_hand  = 10
reserved = 2
available = 8
```

After committing quantity 2:

```text
on_hand  = 8
reserved = 0
available = 8
```

All operations happen transactionally.

---

# 28. Inventory Adjustment

Admin may adjust stock.

Endpoint:

```text
POST /api/v1/admin/inventory/{inventory}/adjustments
```

Payload:

```json
{
  "type": "INCREASE",
  "quantity": 10,
  "reason": "Warehouse restock"
}
```

Types:

```text
INCREASE
DECREASE
CORRECTION
```

Every adjustment must generate:

- audit log
- inventory movement record
- optional domain event

---

# 29. Inventory Movement Ledger

Create immutable inventory movement history.

```text
inventory_movements

id
inventory_id
type
quantity
reference_type
reference_id
balance_after
reason
created_at
```

Movement types:

```text
RESTOCK
RESERVE
RELEASE
SALE
ADJUSTMENT
```

This gives interviewers a clear audit trail.

---

# 30. Redis Usage

Redis should be used for:

- product cache
- rate limiting
- short-lived API data
- queue support if needed by Laravel internals
- realtime / WebSocket support where appropriate

Redis should NOT be the source of truth for:

- inventory quantity
- order state
- payment state

---

# 31. Cache Strategy

Example:

```text
GET /products/{id}
```

Cache key:

```text
product:{id}
```

TTL:

```text
5 minutes
```

Invalidate cache when product changes.

Inventory availability may use short caching only if correctness is never derived from the cache.

Checkout must always validate against PostgreSQL.

---

# 32. Realtime Updates

Use WebSockets for:

```text
order.created
order.paid
order.expired
order.completed
payment.failed
```

Channel example:

```text
private-orders.{orderId}
```

Customer UI should update without polling when order status changes.

---

# 33. API Design

Base URL:

```text
/api/v1
```

Recommended endpoints:

## Authentication

```text
POST /auth/register
POST /auth/login
POST /auth/logout
GET  /auth/me
```

## Products

```text
GET /products
GET /products/{id}
```

## Orders

```text
POST /orders
GET  /orders
GET  /orders/{id}
POST /orders/{id}/cancel
```

## Payments

```text
POST /orders/{id}/payments
POST /payments/{id}/mock-success
POST /payments/{id}/mock-failure
POST /payments/webhook/mock
```

## Admin Inventory

```text
GET  /admin/inventory
GET  /admin/inventory/{id}
POST /admin/inventory/{id}/adjustments
```

## Admin Operations

```text
GET  /admin/orders
GET  /admin/payments
GET  /admin/outbox
GET  /admin/dead-letters
POST /admin/dead-letters/{id}/retry
```

## System

```text
GET /health
GET /health/live
GET /health/ready
GET /metrics
```

---

# 34. API Response Format

Success:

```json
{
  "data": {},
  "meta": {},
  "request_id": "..."
}
```

Error:

```json
{
  "error": {
    "code": "INSUFFICIENT_STOCK",
    "message": "Requested quantity is not available.",
    "details": {}
  },
  "request_id": "..."
}
```

Do not return raw stack traces in production mode.

---

# 35. Error Codes

Minimum domain errors:

```text
PRODUCT_NOT_FOUND
INSUFFICIENT_STOCK
INVALID_ORDER_STATE
ORDER_EXPIRED
ORDER_ALREADY_PAID
PAYMENT_ALREADY_PROCESSED
INVALID_WEBHOOK_SIGNATURE
IDEMPOTENCY_CONFLICT
RATE_LIMIT_EXCEEDED
UNAUTHORIZED
FORBIDDEN
VALIDATION_ERROR
```

---

# 36. Authentication & Authorization

Recommended:

- Laravel Sanctum
- API token/session appropriate to frontend architecture

Roles:

```text
CUSTOMER
ADMIN
```

Optional future role:

```text
OPERATIONS
```

Use Laravel Policies / Gates.

Never place authorization logic only in controllers.

---

# 37. Security Requirements

Minimum:

- Strong password hashing
- Request validation
- Authorization policies
- Rate limiting
- CSRF protection where applicable
- HMAC webhook validation
- Safe error responses
- SQL injection protection through ORM/query builder
- Secure secrets through environment variables
- No secrets committed to repository
- CORS configuration
- Security headers through Nginx/app middleware

---

# 38. Request Correlation

Every incoming request should have a:

```text
request_id
```

If client sends:

```text
X-Request-ID
```

reuse it if valid.

Otherwise generate UUID.

Include request ID in:

- API response
- logs
- audit logs
- domain events
- queue jobs when possible

This makes distributed troubleshooting explainable.

---

# 39. Structured Logging

Logs should be machine-readable JSON in production.

Example:

```json
{
  "level": "info",
  "message": "Order created",
  "request_id": "req_...",
  "order_id": "ord_...",
  "user_id": 10,
  "timestamp": "..."
}
```

Do not log:

- passwords
- access tokens
- secrets
- full sensitive payment details

---

# 40. Metrics

Minimum useful metrics:

```text
http_requests_total
http_request_duration_seconds
orders_created_total
orders_paid_total
orders_expired_total
payments_failed_total
inventory_reservation_failures_total
queue_jobs_failed_total
outbox_publish_failures_total
```

Recommended dashboard:

- request rate
- error rate
- latency
- queue depth
- order success rate
- payment failures
- inventory contention

---

# 41. Health Checks

## `/health/live`

Application process is alive.

## `/health/ready`

Checks:

- PostgreSQL
- Redis
- RabbitMQ

Return degraded status if dependencies are unavailable.

---

# 42. Database Constraints

Business integrity must be supported by database constraints where reasonable.

Examples:

```text
unique(products.sku)
unique(orders.order_number)
unique(payments.payment_reference)
unique(payment_webhooks.external_event_id)
unique(outbox_events.event_id)
```

Checks:

```text
inventory.on_hand >= 0
inventory.reserved >= 0
inventory.reserved <= inventory.on_hand
```

Do not trust application validation alone.

---

# 43. Database Indexing

At minimum evaluate indexes for:

```text
orders.customer_id
orders.status
orders.created_at

payments.order_id
payments.status

stock_reservations.order_id
stock_reservations.status
stock_reservations.expires_at

outbox_events.status
outbox_events.available_at

inventory_movements.inventory_id
inventory_movements.created_at
```

Use `EXPLAIN ANALYZE` for selected queries and document findings.

---

# 44. Backend Folder Structure

Recommended Laravel organization:

```text
app/
├── Domain/
│   ├── Catalog/
│   │   ├── Actions/
│   │   ├── DTOs/
│   │   ├── Events/
│   │   ├── Models/
│   │   └── Services/
│   │
│   ├── Inventory/
│   ├── Orders/
│   ├── Payments/
│   └── Audit/
│
├── Application/
│   ├── Commands/
│   ├── Queries/
│   └── Handlers/
│
├── Infrastructure/
│   ├── Messaging/
│   ├── Persistence/
│   ├── Observability/
│   └── Webhooks/
│
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   ├── Requests/
│   └── Resources/
│
└── Shared/
    ├── Exceptions/
    ├── Idempotency/
    └── Support/
```

Do not over-engineer abstractions without value.

The goal is clean separation, not architecture cosplay.

---

# 45. Coding Principles

All implementation should follow:

- SOLID
- Dependency inversion where useful
- Small focused services
- Explicit domain rules
- Thin controllers
- Request validation classes
- Resource / serializer classes
- Transactions in application/domain service layer
- No business logic in controllers
- Avoid generic Repository pattern if Eloquent already provides enough abstraction
- Avoid unnecessary interfaces
- Avoid god services
- Avoid static helpers for domain logic
- Prefer immutable DTOs where useful
- Use enums for finite states
- Use value objects for concepts where they add clarity

---

# 46. Testing Strategy

Testing is a major portfolio requirement.

## Unit Tests

Test:

- order state transitions
- payment state transitions
- price calculations
- reservation rules
- domain validation
- signature verification

## Feature / Integration Tests

Test:

- create order
- insufficient stock
- order cancellation
- payment success
- payment failure
- duplicate webhook
- expired order
- inventory release
- idempotent order creation
- authorization
- admin stock adjustment

## Concurrency Tests

Critical.

Create test:

```text
inventory = 1

send two concurrent order requests

expected:
1 success
1 failure
final stock consistent
```

Another:

```text
inventory = 10

20 concurrent requests for quantity 1

expected:
10 success
10 rejected
no negative stock
```

These tests are among the strongest interview talking points.

---

# 47. Failure Simulation

Provide local dev endpoints or CLI commands to simulate:

```text
payment delay
payment failure
duplicate webhook
RabbitMQ outage
Redis outage
outbox publisher failure
worker retry
```

Only enable debugging/simulation endpoints outside production.

---

# 48. Seed Data

Seeder should create:

## Users

```text
admin@orderforge.test
customer1@orderforge.test
customer2@orderforge.test
```

Use documented local-development passwords only.

## Warehouses

```text
JKT-01
SBY-01
```

## Products

At least 20 products.

Include examples:

```text
Mechanical Keyboard
Wireless Mouse
USB-C Hub
27-inch Monitor
Laptop Stand
Webcam
Headset
SSD 1TB
```

Inventory levels should vary.

At least one product should have:

```text
available stock = 1
```

for easy concurrency demonstration.

---

# 49. Admin Dashboard

Keep simple.

Pages:

```text
Dashboard
Orders
Order Detail
Inventory
Inventory Detail
Payments
Outbox Events
Dead Letters
Audit Logs
```

Dashboard cards:

```text
Orders today
Paid orders
Expired orders
Payment failures
Low-stock products
Failed jobs
```

The dashboard exists to visualize backend behavior, not to become the project focus.

---

# 50. Customer UI

Pages:

```text
Product List
Product Detail
Cart
Checkout
Order Detail
Payment Simulation
My Orders
```

Order detail should update realtime.

---

# 51. Docker Compose

Expected services:

```text
nginx
app
worker
scheduler
postgres
redis
rabbitmq
frontend
```

Optional:

```text
prometheus
grafana
```

Expected command:

```bash
docker compose up -d
```

A fresh developer should be able to start the project with minimal manual setup.

---

# 52. Environment Configuration

Provide:

```text
.env.example
```

Never commit:

```text
.env
credentials
private keys
tokens
```

Important configs:

```text
APP_ENV
APP_KEY

DB_*

REDIS_*

RABBITMQ_*

WEBHOOK_SECRET

ORDER_PAYMENT_TTL

IDEMPOTENCY_TTL
```

---

# 53. CI/CD

GitHub Actions should run on pull request and push.

Pipeline:

```text
checkout
↓
install dependencies
↓
lint
↓
static analysis
↓
unit tests
↓
integration tests
↓
build
```

Recommended PHP tools:

- Laravel Pint
- PHPStan / Larastan
- PHPUnit or Pest

Frontend:

- ESLint
- TypeScript check
- test/build

Docker image build may be added after core stability.

---

# 54. Definition of Done

A feature is not done unless:

- business behavior implemented
- validation implemented
- authorization implemented where relevant
- error handling implemented
- database constraints considered
- tests added
- logs added for important state changes
- API docs updated
- code passes lint/static analysis
- no debug code remains

---

# 55. Development Phases

---

## Phase 0 — Foundation

Deliverables:

- Laravel project
- Next.js project
- Docker Compose
- PostgreSQL
- Redis
- RabbitMQ
- authentication
- base API response format
- request ID middleware
- linting
- static analysis
- CI skeleton

Acceptance:

```text
docker compose up -d
```

starts the stack.

Health endpoint reports dependencies.

---

## Phase 1 — Catalog & Inventory

Implement:

- products
- warehouses
- inventory
- inventory movement ledger
- admin stock adjustments
- caching

Acceptance:

- admin can restock
- stock never goes negative
- adjustments are audited
- product API works

---

## Phase 2 — Order Creation

Implement:

- orders
- order items
- stock reservation
- transaction
- row locking
- idempotency

Acceptance:

- successful checkout reserves stock
- insufficient stock rolls back
- duplicate request does not duplicate order
- concurrency test passes

This phase is the first major portfolio milestone.

---

## Phase 3 — Payments

Implement:

- payment entity
- mock provider
- webhook
- HMAC signature
- duplicate webhook protection
- payment state machine

Acceptance:

- successful payment marks order paid
- duplicate webhook has no duplicate side effect
- invalid signature rejected

---

## Phase 4 — Expiration & Recovery

Implement:

- order expiration
- reservation release
- scheduler
- retry strategy
- failure scenarios

Acceptance:

- unpaid order expires automatically
- stock is restored exactly once
- retries are safe

---

## Phase 5 — Event-Driven Infrastructure

Implement:

- transactional outbox
- outbox publisher
- RabbitMQ
- event consumers
- DLQ

Acceptance:

- domain state and event creation are atomic
- publishing can recover after RabbitMQ downtime
- dead letters are visible

---

## Phase 6 — Realtime & Dashboard

Implement:

- WebSockets
- admin dashboard
- customer order status updates
- operational views

Acceptance:

- order status changes appear without manual refresh
- admins can inspect event / failure state

---

## Phase 7 — Observability

Implement:

- structured logs
- metrics
- dashboards
- enhanced health endpoints

Acceptance:

- failed payment traceable by request ID
- queue failure visible
- system dependencies visible

---

## Phase 8 — Hardening

Implement:

- performance testing
- EXPLAIN ANALYZE
- security review
- API documentation
- README
- architecture diagrams
- demo script

Acceptance:

Project is ready to show to recruiter/interviewer.

---

# 56. MVP Scope

The minimum version worth putting on a CV must include:

- Products
- Inventory
- Orders
- Atomic reservations
- Concurrency protection
- Idempotency
- Mock payments
- Duplicate webhook protection
- Order expiration
- Inventory release
- Transactional outbox
- RabbitMQ
- Retry + DLQ
- Docker
- Automated tests
- CI
- README architecture explanation

Do not publish the project as finished before this scope is complete.

---

# 57. Stretch Features

Only after MVP:

- multiple warehouses allocation
- split fulfillment
- refunds
- partial refunds
- reservation optimization
- load testing with k6
- tracing with OpenTelemetry
- GraphQL read API
- CQRS read model
- PostgreSQL read replica simulation
- S3-compatible file storage
- Kubernetes manifests

Each stretch feature must justify itself.

---

# 58. Performance Test Scenarios

Recommended later using k6.

## Scenario A

```text
100 users
browse products
```

## Scenario B

```text
50 concurrent customers
buy same limited-stock product
```

## Scenario C

```text
100 webhook deliveries/sec
including duplicates
```

Capture:

- throughput
- p95 latency
- error rate
- DB contention
- queue backlog

---

# 59. Important Invariants

The following must always remain true:

```text
inventory.on_hand >= 0

inventory.reserved >= 0

inventory.reserved <= inventory.on_hand

paid order has successful payment

expired order cannot become paid

cancelled order cannot become paid

same webhook event cannot create side effect twice

same idempotency key cannot create duplicate business operation

committed/released reservation cannot be processed twice
```

Tests should explicitly verify these invariants.

---

# 60. Interview Talking Points

The project should allow the developer to confidently explain:

## Why PostgreSQL locking?

Because inventory is a strongly consistent business invariant and PostgreSQL is the source of truth.

## Why not Redis lock?

Redis locks can coordinate processes but should not replace transactional database correctness for inventory state.

## Why Transactional Outbox?

To avoid losing events when the database commits but the message broker publish fails.

## Why RabbitMQ instead of Kafka?

The project needs reliable queues, retries, routing, and DLQ more than high-throughput event streaming.

## How is duplicate payment prevented?

Unique external event ID + transaction + state validation.

## How is overselling prevented?

Row-level inventory locking within the checkout transaction.

## Why modular monolith?

It gives strong boundaries without microservice operational overhead.

## How would it scale?

Potential future extraction:

```text
Orders
Inventory
Payments
Notifications
```

but only after clear scaling or ownership requirements.

---

# 61. README Requirements

README must contain:

1. Project overview
2. Problem being solved
3. Architecture diagram
4. Technology stack
5. Setup instructions
6. Docker instructions
7. Environment variables
8. Database model overview
9. API documentation link
10. Order lifecycle
11. Payment lifecycle
12. Concurrency explanation
13. Idempotency explanation
14. Transactional Outbox explanation
15. RabbitMQ / DLQ explanation
16. Testing strategy
17. How to run concurrency demo
18. Observability screenshots
19. Design decisions and tradeoffs
20. Future improvements

---

# 62. Demo Scenario for Recruiters

The final project should support a 5-minute demo.

## Demo 1 — Normal Purchase

```text
Create order
→ stock reserved
→ mock payment
→ webhook
→ order paid
→ inventory committed
```

## Demo 2 — Concurrency

```text
stock = 1

run two purchases simultaneously

result:
1 success
1 insufficient stock
```

## Demo 3 — Duplicate Webhook

Send same webhook 5 times.

Result:

```text
payment processed once
```

## Demo 4 — Expired Order

```text
create unpaid order
→ expiration worker runs
→ reservation released
```

## Demo 5 — Broker Failure

```text
stop RabbitMQ
→ create order
→ database succeeds
→ outbox remains pending
→ restore RabbitMQ
→ event eventually published
```

This is an excellent demonstration of reliability engineering.

---

# 63. Suggested Repository Structure

Option A: Monorepo

```text
orderforge/
├── backend/
├── frontend/
├── infrastructure/
├── docs/
├── scripts/
├── docker-compose.yml
├── Makefile
└── README.md
```

Recommended for this portfolio.

---

# 64. Makefile / Developer Commands

Recommended:

```bash
make up
make down
make build
make install
make migrate
make seed
make test
make lint
make analyse
make logs
make reset
```

Goal:

A reviewer should not need to remember long Docker commands.

---

# 65. API Documentation

Generate OpenAPI documentation.

Requirements:

- request schema
- response schema
- validation errors
- authentication requirement
- idempotency headers
- example payload
- error codes

Swagger UI may be exposed locally.

---

# 66. Git Workflow

Recommended branches:

```text
main
feature/*
fix/*
refactor/*
```

Commit examples:

```text
feat(order): add atomic stock reservation
feat(payment): handle idempotent webhook processing
fix(inventory): prevent double reservation release
test(order): add concurrent checkout scenario
docs(architecture): explain transactional outbox
```

Avoid giant commits.

---

# 67. Engineering Rules for Codex

Codex must follow these rules during implementation.

## Rule 1

Do not generate the entire system in one giant step.

Implement phase by phase.

## Rule 2

Before coding a phase:

1. inspect existing code
2. state intended changes
3. identify affected modules
4. identify migrations
5. identify tests

Then implement.

## Rule 3

Never replace working architecture without justification.

## Rule 4

No business logic in controllers.

Controllers should:

```text
validate / receive request
call application service/action
return resource
```

## Rule 5

Every critical business operation requires tests.

Especially:

```text
checkout
inventory reservation
payment webhook
expiration
reservation release
outbox publication
```

## Rule 6

Use database transactions for multi-write business operations.

## Rule 7

Do not fake concurrency safety.

Concurrency-critical logic must be implemented at the database level.

## Rule 8

Do not add microservices unless explicitly requested.

## Rule 9

Do not add Kafka unless requirements justify it.

RabbitMQ is the default broker.

## Rule 10

Prefer simple, explicit code over abstract architecture.

## Rule 11

Run tests after meaningful changes.

## Rule 12

Update README/API docs when behavior changes.

## Rule 13

Do not modify unrelated files.

## Rule 14

Never commit secrets.

## Rule 15

If requirements conflict, protect business invariants first.

---

# 68. Codex Kickoff Prompt

Use the prompt below when starting implementation with Codex.

```text
You are implementing OrderForge, a production-style distributed order and inventory management portfolio project.

Read the entire OrderForge PRD before making changes.

Primary objective:
Build a backend-focused system that demonstrates concurrency safety, transactional consistency, idempotency, event-driven architecture, failure recovery, observability, automated testing, Docker, and CI/CD.

Important engineering priorities:
1. Correctness before speed.
2. PostgreSQL is the source of truth.
3. Prevent overselling using transactions and row-level locking.
4. All critical write APIs must be idempotent where specified.
5. Payment webhooks must tolerate duplicate delivery.
6. Use Transactional Outbox for reliable event publishing.
7. Use RabbitMQ, not Kafka, unless the requirement changes.
8. Use a modular monolith, not premature microservices.
9. Keep controllers thin.
10. Follow SOLID principles without unnecessary abstraction.
11. Every critical domain behavior requires automated tests.
12. Do not weaken database constraints for convenience.
13. Do not skip concurrency tests.
14. Keep frontend simple. Backend engineering is the priority.

Development process:
- Work phase by phase according to the PRD.
- Before starting a phase, inspect the repository and summarize what already exists.
- Propose the smallest correct implementation for the current phase.
- Implement it.
- Run lint/static analysis/tests.
- Fix failures before moving on.
- Update documentation when behavior or architecture changes.

Start with Phase 0 only unless the repository already contains part of it.

Before coding:
1. inspect repository structure
2. identify current stack and configuration
3. compare current state against Phase 0
4. present a concise implementation plan
5. then execute the plan

Do not attempt to generate every phase at once.
```

---

# 69. CV Description

Suggested future CV bullet after implementation:

```text
OrderForge — Distributed Order & Inventory Management System

Built a production-style transactional backend using Laravel, PostgreSQL, Redis, and RabbitMQ, implementing concurrency-safe stock reservations, idempotent APIs, payment webhook processing, transactional outbox messaging, retry/DLQ workflows, Dockerized infrastructure, and automated concurrency/integration testing.
```

Shorter version:

```text
Built a Laravel-based order and inventory platform with concurrency-safe stock reservation, idempotent payments, RabbitMQ event processing, Redis caching, transactional outbox, Docker, CI/CD, and automated integration tests.
```

---

# 70. Final Success Criteria

OrderForge is considered portfolio-ready when a reviewer can:

```text
git clone
↓
docker compose up
↓
seed database
↓
open application
↓
create an order
↓
simulate payment
↓
observe realtime state
↓
run automated tests
↓
run concurrency demo
```

and the system can clearly demonstrate:

```text
No overselling
No duplicate payment side effects
No lost critical events
Safe retries
Automatic stock recovery
Traceable operations
Repeatable local setup
Tested business invariants
```

---

# 71. Final Principle

Do not optimize OrderForge for feature count.

Optimize it for **engineering depth**.

A small system that correctly handles:

- concurrency
- failures
- transactions
- retries
- idempotency
- observability

is more valuable for a backend engineering portfolio than a large application containing dozens of shallow CRUD screens.

The finished project should make this statement believable:

> **“I understand not only how to build an API, but also how to keep business data correct when real systems behave badly.”**
