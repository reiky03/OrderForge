"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api/v1";

type User = { id: number; name: string; email: string; role: "ADMIN" | "CUSTOMER" };
type Envelope<T> = { data: T; error?: { code: string; message: string; details?: { fields?: Record<string, string[]> } }; meta?: { pagination?: { total: number } }; request_id?: string };
type Product = { id: number; sku: string; name: string; description: string | null; price: string; currency: string; available_stock: number };
type Order = { id: number; order_number: string; status: string; total: string; currency: string; expires_at: string | null; created_at: string; items: { product_id: number; sku: string; name: string; quantity: number; unit_price: string; subtotal: string }[]; payment: Payment | null };
type Payment = { id: number; order_id: number; payment_reference: string; status: string; amount: string; currency: string; paid_at: string | null; failed_at: string | null };
type Inventory = { id: number; product: { id: number; sku: string; name: string }; warehouse: { id: number; code: string; name: string }; on_hand: number; reserved: number; available: number; version: number };
type Outbox = { id: number; event_id: string; event_type: string; aggregate_type: string; aggregate_id: string; status: string; attempts: number; last_error: string | null; created_at: string };
type DeadLetter = { id: number; event_id: string; event_type: string; status: string; attempts: number; failure_reason: string; failed_at: string };
type FailedJob = { id: number; uuid: string; queue: string; failed_at: string; summary: string };
type RealtimeEcho = {
  private: (channel: string) => { listen: (event: string, callback: (event: { order_id: number }) => void) => void };
  disconnect: () => void;
  connector: { pusher: { connection: { state: string; bind: (event: string, callback: () => void) => void } } };
};

class ApiError extends Error {
  constructor(message: string, readonly code = "REQUEST_FAILED") { super(message); }
}

async function api<T>(path: string, token?: string, body?: unknown, extraHeaders: Record<string, string> = {}): Promise<Envelope<T>> {
  const response = await fetch(`${API}${path}`, {
    method: body === undefined ? "GET" : "POST",
    headers: {
      Accept: "application/json",
      ...(body === undefined ? {} : { "Content-Type": "application/json" }),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...extraHeaders,
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    cache: "no-store",
  });
  const payload = (await response.json()) as Envelope<T>;
  if (!response.ok) throw new ApiError(payload.error?.message ?? "The request could not be completed.", payload.error?.code);
  return payload;
}

function money(value: string | number, currency = "IDR") {
  return new Intl.NumberFormat("id-ID", { style: "currency", currency, maximumFractionDigits: 0 }).format(Number(value));
}

function label(value: string) {
  return value.replaceAll("_", " ").toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export default function Dashboard() {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState("");
  const [booting, setBooting] = useState(true);
  const [email, setEmail] = useState("customer1@orderforge.test");
  const [password, setPassword] = useState("OrderForgeLocal2026!");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState("overview");
  const [products, setProducts] = useState<Product[]>([]);
  const [orders, setOrders] = useState<Order[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [inventory, setInventory] = useState<Inventory[]>([]);
  const [outbox, setOutbox] = useState<Outbox[]>([]);
  const [deadLetters, setDeadLetters] = useState<DeadLetter[]>([]);
  const [failedJobs, setFailedJobs] = useState<FailedJob[]>([]);
  const [cart, setCart] = useState<Record<number, number>>({});
  const [search, setSearch] = useState("");
  const [catalogFilter, setCatalogFilter] = useState<"all" | "available" | "limited">("all");
  const [sortBy, setSortBy] = useState<"name" | "price-low" | "price-high">("name");
  const [cartOpen, setCartOpen] = useState(false);
  const [expandedOrder, setExpandedOrder] = useState<number | null>(null);
  const [adjusting, setAdjusting] = useState<Inventory | null>(null);
  const [adjustType, setAdjustType] = useState("INCREASE");
  const [adjustQuantity, setAdjustQuantity] = useState("1");
  const [adjustReason, setAdjustReason] = useState("Demo warehouse correction");
  const [liveConnected, setLiveConnected] = useState(false);

  const isAdmin = user?.role === "ADMIN";
  const filteredProducts = useMemo(() => products
    .filter((product) => `${product.name} ${product.sku}`.toLowerCase().includes(search.toLowerCase()))
    .filter((product) => catalogFilter === "all" || (catalogFilter === "available" ? product.available_stock > 0 : product.available_stock > 0 && product.available_stock <= 5))
    .sort((a, b) => sortBy === "price-low" ? Number(a.price) - Number(b.price) : sortBy === "price-high" ? Number(b.price) - Number(a.price) : a.name.localeCompare(b.name)), [products, search, catalogFilter, sortBy]);
  const cartCount = Object.values(cart).reduce((sum, quantity) => sum + quantity, 0);
  const cartTotal = products.reduce((sum, product) => sum + (cart[product.id] ?? 0) * Number(product.price), 0);

  const refreshProducts = useCallback(async () => {
    const response = await api<Product[]>("/products?per_page=100");
    setProducts(response.data);
  }, []);

  const refreshOrders = useCallback(async (accessToken = token, role = user?.role) => {
    if (!accessToken) return;
    const response = await api<Order[]>(role === "ADMIN" ? "/admin/orders?per_page=100" : "/orders?per_page=100", accessToken);
    setOrders(response.data);
  }, [token, user?.role]);

  const refreshAdmin = useCallback(async (accessToken = token, role = user?.role) => {
    if (!accessToken || role !== "ADMIN") return;
    const [stock, events, failures, paymentRows, jobs] = await Promise.all([
      api<Inventory[]>("/admin/inventory?per_page=100", accessToken),
      api<Outbox[]>("/admin/outbox?per_page=100", accessToken),
      api<DeadLetter[]>("/admin/dead-letters?per_page=100", accessToken),
      api<Payment[]>("/admin/payments?per_page=100", accessToken),
      api<FailedJob[]>("/admin/failed-jobs?per_page=100", accessToken),
    ]);
    setInventory(stock.data);
    setOutbox(events.data);
    setDeadLetters(failures.data);
    setPayments(paymentRows.data);
    setFailedJobs(jobs.data);
  }, [token, user?.role]);

  const refreshAll = useCallback(async (accessToken = token, role = user?.role) => {
    await Promise.all([refreshProducts(), refreshOrders(accessToken, role), refreshAdmin(accessToken, role)]);
  }, [refreshAdmin, refreshOrders, refreshProducts, token, user?.role]);

  useEffect(() => {
    void (async () => {
      await Promise.resolve();
      const saved = localStorage.getItem("orderforge-session");
      if (!saved) {
        try { await refreshProducts(); } catch { setError("API is not available yet."); }
        setBooting(false);
        return;
      }
      try {
        const session = JSON.parse(saved) as { token: string; user: User };
        setToken(session.token);
        setUser(session.user);
        await refreshAll(session.token, session.user.role);
      } catch {
        localStorage.removeItem("orderforge-session");
        setToken("");
        setUser(null);
        setError("Your session expired. Please sign in again.");
      } finally { setBooting(false); }
    })();
  // Initial session restoration only.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!token || !orders.length) return;
    let echo: RealtimeEcho | null = null;
    let active = true;
    void (async () => {
      try {
        const [{ default: Echo }, { default: Pusher }] = await Promise.all([import("laravel-echo"), import("pusher-js")]);
        if (!active) return;
        Object.assign(window, { Pusher });
        echo = new Echo({
          broadcaster: "reverb",
          key: process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? "orderforge-local-key",
          wsHost: process.env.NEXT_PUBLIC_REVERB_HOST ?? "localhost",
          wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
          wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
          forceTLS: process.env.NEXT_PUBLIC_REVERB_SCHEME === "https",
          enabledTransports: ["ws", "wss"],
          authEndpoint: `${API}/broadcasting/auth`,
          auth: { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } },
        }) as RealtimeEcho;
        const activeOrders = orders.filter((order) => ["PENDING_PAYMENT", "PAID", "PROCESSING"].includes(order.status));
        for (const order of activeOrders) {
          echo.private(`orders.${order.id}`).listen(".order.status.changed", () => {
            void refreshOrders(token);
            if (isAdmin) void refreshAdmin(token);
          });
        }
        const connection = echo.connector.pusher.connection;
        connection.bind("connected", () => { if (active) setLiveConnected(true); });
        connection.bind("disconnected", () => { if (active) setLiveConnected(false); });
        connection.bind("unavailable", () => { if (active) setLiveConnected(false); });
        setLiveConnected(connection.state === "connected");
      } catch {
        setLiveConnected(false);
      }
    })();
    return () => {
      active = false;
      echo?.disconnect();
      setLiveConnected(false);
    };
  }, [isAdmin, orders, refreshAdmin, refreshOrders, token]);

  async function signIn(event: FormEvent) {
    event.preventDefault();
    setBusy(true); setError("");
    try {
      const result = await api<{ token: string; user: User }>("/auth/login", undefined, { email, password });
      localStorage.setItem("orderforge-session", JSON.stringify(result.data));
      setToken(result.data.token); setUser(result.data.user); setTab("overview");
      await refreshAll(result.data.token, result.data.user.role);
      setNotice(`Welcome, ${result.data.user.name}.`);
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Sign in failed."); }
    finally { setBusy(false); }
  }

  async function signOut() {
    try { await api("/auth/logout", token, {}); } catch { /* Local logout still clears an expired token. */ }
    localStorage.removeItem("orderforge-session"); setToken(""); setUser(null); setOrders([]); setTab("overview");
  }

  function addToCart(product: Product) {
    setCart((current) => ({ ...current, [product.id]: Math.min((current[product.id] ?? 0) + 1, product.available_stock) }));
  }

  function changeQuantity(product: Product, amount: number) {
    setCart((current) => {
      const next = { ...current };
      const quantity = Math.min(product.available_stock, Math.max(0, (current[product.id] ?? 0) + amount));
      if (quantity === 0) delete next[product.id]; else next[product.id] = quantity;
      return next;
    });
  }

  async function checkout() {
    if (!user || user.role !== "CUSTOMER") return;
    setBusy(true); setError("");
    try {
      const created = await api<Order>("/orders", token, { items: Object.entries(cart).filter(([, quantity]) => quantity > 0).map(([product_id, quantity]) => ({ product_id: Number(product_id), quantity })) }, { "Idempotency-Key": crypto.randomUUID() });
      await api<Payment>(`/orders/${created.data.id}/payments`, token, {}, { "Idempotency-Key": crypto.randomUUID() });
      setCart({}); setCartOpen(false); setExpandedOrder(created.data.id); setTab("orders"); setNotice(`Order ${created.data.order_number} was reserved. Complete the mock payment below.`);
      await refreshAll();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Checkout failed."); }
    finally { setBusy(false); }
  }

  async function simulatePayment(paymentId: number, succeed: boolean) {
    setBusy(true); setError("");
    try {
      await api(`/payments/${paymentId}/${succeed ? "mock-success" : "mock-failure"}`, token, { duplicate_deliveries: 3 });
      setNotice(succeed ? "Payment succeeded. Stock is committed and order status updated live." : "Payment failed. The reservation remains until recovery expires it.");
      await refreshAll();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Payment simulation failed."); }
    finally { setBusy(false); }
  }

  async function simulateTimeout(paymentId: number) {
    setBusy(true); setError("");
    try {
      await api(`/payments/${paymentId}/mock-timeout`, token, {});
    } catch (cause) {
      if (cause instanceof ApiError && cause.code === "MOCK_PROVIDER_TIMEOUT") {
        setNotice("The mock provider timed out. Payment and reserved stock remain pending.");
      } else {
        setError(cause instanceof Error ? cause.message : "Timeout simulation failed.");
      }
    } finally { setBusy(false); }
  }

  async function startPayment(orderId: number) {
    setBusy(true); setError("");
    try {
      await api(`/orders/${orderId}/payments`, token, {}, { "Idempotency-Key": crypto.randomUUID() });
      setNotice("Payment session created. You can now simulate its result.");
      await refreshAll();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Could not create payment."); }
    finally { setBusy(false); }
  }

  async function cancelOrder(orderId: number) {
    setBusy(true); setError("");
    try { await api(`/orders/${orderId}/cancel`, token, {}); setNotice("Order cancelled and reserved stock released."); await refreshAll(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Could not cancel order."); }
    finally { setBusy(false); }
  }

  async function adjustStock(event: FormEvent) {
    event.preventDefault(); if (!adjusting) return;
    setBusy(true); setError("");
    try {
      await api(`/admin/inventory/${adjusting.id}/adjustments`, token, { type: adjustType, quantity: Number(adjustQuantity), reason: adjustReason });
      setAdjusting(null); setNotice("Inventory adjustment recorded in the stock ledger."); await refreshAll();
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Stock adjustment failed."); }
    finally { setBusy(false); }
  }

  async function retryDeadLetter(id: number) {
    setBusy(true); setError("");
    try { await api(`/admin/dead-letters/${id}/retry`, token, {}); setNotice("Dead-letter event sent back to RabbitMQ."); await refreshAdmin(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Retry failed."); }
    finally { setBusy(false); }
  }

  useEffect(() => {
    if (!cartOpen && !adjusting) return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") { setCartOpen(false); setAdjusting(null); }
    };
    window.addEventListener("keydown", closeOnEscape);
    return () => { document.body.style.overflow = previousOverflow; window.removeEventListener("keydown", closeOnEscape); };
  }, [cartOpen, adjusting]);

  const menu = isAdmin
    ? [["overview", "Overview"], ["orders", "Orders"], ["payments", "Payments"], ["inventory", "Inventory"], ["operations", "Event pipeline"]]
    : [["overview", "Shop"], ["orders", "My orders"]];

  return (
    <main className="of-app">
      <div className="of-shell">
        <header className="of-topbar">
          <Link href="/" className="of-brand" aria-label="OrderForge home" onClick={() => setTab("overview")}>
            <span className="of-brand-mark"><span /></span>
            <span>order<span className="of-brand-light">forge</span><small>COMMERCE SYSTEM</small></span>
          </Link>
          {user && <nav className="of-nav" aria-label="Main navigation">
            {menu.map(([key, title]) => <button key={key} type="button" onClick={() => setTab(key)} className={`of-nav-item ${tab === key ? "is-active" : ""}`} aria-current={tab === key ? "page" : undefined}>{title}</button>)}
          </nav>}
          <div className="of-top-actions">
            {user ? <>
              {!isAdmin && <button type="button" className="of-cart-trigger" onClick={() => setCartOpen(true)} aria-label={`Open order bag, ${cartCount} item${cartCount === 1 ? "" : "s"}`}><Icon name="bag"/><span className="of-cart-count">{cartCount}</span></button>}
              <div className="of-user"><span className="of-avatar">{user.name.split(" ").map((part) => part[0]).slice(0, 2).join("")}</span><span className="of-user-copy"><strong>{user.name}</strong><small>{isAdmin ? "Administrator" : "Customer account"}</small></span></div>
              <button type="button" className="of-icon-button" onClick={() => void signOut()} aria-label="Sign out" title="Sign out"><Icon name="logout"/></button>
            </> : <a className="of-status-link" href="http://127.0.0.1:8000/health/ready" target="_blank" rel="noreferrer"><span className="of-live-dot"/>System status <Icon name="arrow-up"/></a>}
          </div>
        </header>

        {user && <nav className="of-mobile-nav" aria-label="Mobile navigation">{menu.map(([key, title]) => <button key={key} type="button" onClick={() => setTab(key)} className={tab === key ? "is-active" : ""}>{title}</button>)}</nav>}

        {!user && <section className="of-guest">
          <div className="of-guest-hero">
            <div className="of-eyebrow"><span className="of-spark"/> THE COMMERCE CONTROL ROOM</div>
            <h1>Every order.<br/><em>In its place.</em></h1>
            <p>One calm workspace for stock, payments, and the moments where commerce gets complicated.</p>
            <div className="of-guest-visual" aria-hidden="true"><div className="of-orbit of-orbit-one"/><div className="of-orbit of-orbit-two"/><div className="of-orbit-center"><Icon name="spark"/></div><div className="of-visual-tag tag-one">ORDER <b>CONFIRMED</b></div><div className="of-visual-tag tag-two">STOCK <b>IN SYNC</b></div></div>
            <div className="of-guest-footer"><span>Built for real-world edge cases.</span><span>01 / 04</span></div>
          </div>
          <form onSubmit={signIn} className="of-login-card">
            <div className="of-login-top"><span className="of-overline">WELCOME TO ORDERFORGE</span><span className="of-login-symbol"><Icon name="arrow-up"/></span></div>
            <h2>Sign in to<br/>your workspace.</h2><p>Explore the customer journey or open the operations console.</p>
            <label className="of-field">Email address<input autoComplete="username" type="email" value={email} onChange={(event) => setEmail(event.target.value)} /></label>
            <label className="of-field">Password<input autoComplete="current-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} /></label>
            <button disabled={busy} className="of-button of-button-dark of-button-wide" type="submit">{busy ? "Signing in…" : <>Enter workspace <Icon name="arrow-right"/></>}</button>
            <div className="of-demo-divider"><span>EXPLORE WITH A DEMO ACCOUNT</span></div>
            <div className="of-demo-options"><button type="button" onClick={() => { setEmail("customer1@orderforge.test"); setPassword("OrderForgeLocal2026!"); }}><span><Icon name="bag"/>Customer demo</span><Icon name="arrow-right"/></button><button type="button" onClick={() => { setEmail("admin@orderforge.test"); setPassword("OrderForgeLocal2026!"); }}><span><Icon name="grid"/>Admin demo</span><Icon name="arrow-right"/></button></div>
          </form>
        </section>}

        {user && <section className="of-content">
          <div className="of-page-heading"><div><span className="of-overline">{isAdmin ? "OPERATIONS / LIVE WORKSPACE" : "THE ORDERFORGE SHOP"}</span><h1>{tab === "overview" ? (isAdmin ? "Good to have you here." : "Find your next essential.") : menu.find(([key]) => key === tab)?.[1]}</h1><p>{tab === "overview" ? (isAdmin ? "A clear view of orders, inventory, and the systems behind them." : "Considered tools for better work, ready when you are.") : tab === "orders" ? "Follow every order from reservation through payment." : tab === "inventory" ? "Every unit accounted for, across your warehouses." : tab === "payments" ? "The complete payment trail, at a glance." : "Track every event from creation to delivery."}</p></div><div className="of-page-meta"><span className={`of-live-dot ${liveConnected ? "is-on" : ""}`}/><span>{liveConnected ? "Live updates on" : "System ready"}</span><small>{new Date().toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })}</small></div></div>

          {error && <div role="alert" className="of-alert is-error"><Icon name="alert"/><span>{error}</span><button type="button" onClick={() => setError("")} aria-label="Dismiss">×</button></div>}
          {notice && <div role="status" className="of-alert is-success"><Icon name="check"/><span>{notice}</span><button type="button" onClick={() => setNotice("")} aria-label="Dismiss">×</button></div>}

          {tab === "overview" && (isAdmin ? <AdminOverview orders={orders} inventory={inventory} outbox={outbox} deadLetters={deadLetters} onNavigate={setTab}/> : <>
            <section className="of-customer-hero"><div><span className="of-overline">THE EVERYDAY EDIT / 2026</span><h2>Tools that make<br/><em>work feel lighter.</em></h2><p>Everything you need, thoughtfully selected. Stock stays accurate as you shop.</p><button type="button" className="of-text-action" onClick={() => document.getElementById("catalog")?.scrollIntoView({ behavior: "smooth" })}>Explore collection <Icon name="arrow-right"/></button></div><div className="of-hero-art" aria-hidden="true"><div className="of-hero-ring"/><div className="of-hero-device"><div className="of-hero-screen"/><div className="of-hero-stand"/></div><span className="of-art-caption">A BETTER DESK STARTS HERE ↗</span></div></section>
            <div className="of-stats-strip"><Metric label="Available products" value={String(products.length)} detail="Curated essentials"/><Metric label="Active orders" value={String(orders.filter((order) => order.status === "PENDING_PAYMENT").length)} detail="Awaiting payment"/><Metric label="Completed" value={String(orders.filter((order) => order.status === "PAID").length)} detail="Orders paid"/></div>
            <section id="catalog" className="of-catalog"><div className="of-section-head"><div><span className="of-overline">SHOP THE COLLECTION</span><h2>Explore essentials<span className="of-heading-count">{filteredProducts.length.toString().padStart(2, "0")}</span></h2></div><button type="button" className="of-subtle-button" onClick={() => void refreshProducts()}><Icon name="refresh"/> Refresh stock</button></div><div className="of-catalog-tools"><div className="of-filters" aria-label="Catalog filters">{[["all", "All products"], ["available", "In stock"], ["limited", "Limited stock"]].map(([key, title]) => <button type="button" key={key} className={catalogFilter === key ? "is-active" : ""} onClick={() => setCatalogFilter(key as typeof catalogFilter)}>{title}</button>)}</div><div className="of-catalog-controls"><label className="of-search"><Icon name="search"/><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search products" aria-label="Search products"/>{search && <button type="button" onClick={() => setSearch("")} aria-label="Clear search">×</button>}</label><select value={sortBy} onChange={(event) => setSortBy(event.target.value as typeof sortBy)} aria-label="Sort products"><option value="name">Name A–Z</option><option value="price-low">Price: low to high</option><option value="price-high">Price: high to low</option></select></div></div>
              {filteredProducts.length === 0 ? <Empty title="Nothing in this view" detail="Try another filter or search term."/> : <div className="of-product-grid">{filteredProducts.map((product, index) => <article key={product.id} className="of-product"><div className={`of-product-art art-${index % 4}`}><span className="of-product-index">{String(index + 1).padStart(2, "0")} / 20</span><ProductGlyph sku={product.sku}/><span className="of-product-corner">↗</span></div><div className="of-product-info"><div className="of-product-kicker"><span>{product.sku}</span><span className={product.available_stock <= 5 ? "is-low" : ""}>{product.available_stock > 0 ? (product.available_stock <= 5 ? `Only ${product.available_stock} left` : "In stock") : "Sold out"}</span></div><h3>{product.name}</h3><p>{product.description ?? "Built for everyday work."}</p><div className="of-product-bottom"><strong>{money(product.price, product.currency)}</strong><button type="button" disabled={product.available_stock < 1} onClick={() => addToCart(product)} aria-label={`Add ${product.name} to order`}>{(cart[product.id] ?? 0) > 0 ? <span className="of-added-count">{cart[product.id]}</span> : <Icon name="plus"/>}</button></div></div></article>)}</div>}
            </section>
            {cartCount > 0 && <button type="button" className="of-cart-bar" onClick={() => setCartOpen(true)}><span className="of-cart-bar-icon"><Icon name="bag"/></span><span><small>{cartCount} item{cartCount === 1 ? "" : "s"} in your order</small><strong>{money(cartTotal)}</strong></span><span className="of-cart-bar-cta">Review order <Icon name="arrow-right"/></span></button>}
          </>)}

          {tab === "orders" && <section className="of-list-page"><div className="of-section-head"><div><span className="of-overline">ORDER HISTORY</span><h2>{orders.length} total orders</h2></div><span className="of-muted">Select an order to see its details</span></div>{orders.length === 0 ? <Empty title="No orders yet" detail={isAdmin ? "Customer orders will appear here." : "Your orders and payment updates will appear here."}/> : <div className="of-order-list">{orders.map((order) => <article key={order.id} className={`of-order ${expandedOrder === order.id ? "is-expanded" : ""}`}><button type="button" className="of-order-summary" onClick={() => setExpandedOrder(expandedOrder === order.id ? null : order.id)} aria-expanded={expandedOrder === order.id}><span className="of-order-symbol"><Icon name="bag"/></span><span className="of-order-primary"><strong>{order.order_number}</strong><small>{new Date(order.created_at).toLocaleString("id-ID")} · {order.items.length} item{order.items.length === 1 ? "" : "s"}</small></span><span className="of-order-price">{money(order.total, order.currency)}</span><Status value={order.status}/><Icon name="chevron"/></button>{expandedOrder === order.id && <div className="of-order-details"><div className="of-order-items">{order.items.map((item, index) => <div key={`${item.product_id}-${index}`}><span><strong>{item.name}</strong><small>Quantity {item.quantity} · {item.sku}</small></span><b>{money(item.subtotal, order.currency)}</b></div>)}</div><div className="of-order-footer"><div>{order.payment ? <span className="of-payment-note">Payment <strong>{order.payment.payment_reference}</strong> <Status value={order.payment.status}/></span> : <span className="of-muted">Reservation expires {order.expires_at ? new Date(order.expires_at).toLocaleString("id-ID") : "soon"}</span>}</div>{!isAdmin && order.status === "PENDING_PAYMENT" && <div className="of-order-actions">{!order.payment && <button disabled={busy} onClick={() => void startPayment(order.id)} className="of-button of-button-dark">Create payment</button>}{order.payment?.status === "PENDING" && <><button disabled={busy} onClick={() => void simulatePayment(order.payment!.id, true)} className="of-button of-button-dark">Simulate success</button><button disabled={busy} onClick={() => void simulatePayment(order.payment!.id, false)} className="of-button of-button-outline">Failure</button><button disabled={busy} onClick={() => void simulateTimeout(order.payment!.id)} className="of-button of-button-outline">Timeout</button></>}<button disabled={busy} onClick={() => void cancelOrder(order.id)} className="of-button of-button-danger">Cancel order</button></div>}</div></div>}</article>)}</div>}</section>}

          {isAdmin && tab === "payments" && <section className="of-list-page"><div className="of-section-head"><div><span className="of-overline">PAYMENT LEDGER</span><h2>Payment activity</h2></div><span className="of-muted">Signed webhooks · Deduplicated events</span></div><div className="of-table-wrap"><table className="of-table"><thead><tr><th>Reference</th><th>Order</th><th>Amount</th><th>Provider</th><th>Status</th></tr></thead><tbody>{payments.map((payment) => <tr key={payment.id}><td><strong>{payment.payment_reference}</strong></td><td>#{payment.order_id}</td><td>{money(payment.amount, payment.currency)}</td><td>Mock provider</td><td><Status value={payment.status}/></td></tr>)}</tbody></table></div></section>}

          {isAdmin && tab === "inventory" && <section className="of-list-page"><div className="of-section-head"><div><span className="of-overline">WAREHOUSE / STOCK LEDGER</span><h2>Inventory</h2></div><button type="button" className="of-subtle-button" onClick={() => void refreshAdmin()}><Icon name="refresh"/> Refresh data</button></div><div className="of-table-wrap"><table className="of-table"><thead><tr><th>Product</th><th>Warehouse</th><th>On hand</th><th>Reserved</th><th>Available</th><th></th></tr></thead><tbody>{inventory.map((row) => <tr key={row.id}><td><strong>{row.product.name}</strong><small>{row.product.sku}</small></td><td>{row.warehouse.name}</td><td>{row.on_hand}</td><td>{row.reserved}</td><td><strong>{row.available}</strong></td><td><button type="button" className="of-mini-button" onClick={() => setAdjusting(row)}>Adjust <Icon name="arrow-right"/></button></td></tr>)}</tbody></table></div></section>}

          {isAdmin && tab === "operations" && <section className="of-list-page"><div className="of-section-head"><div><span className="of-overline">EVENT DELIVERY / MONITORING</span><h2>Event pipeline</h2></div><span className="of-live-pill"><span className="of-live-dot is-on"/> Live pipeline</span></div><div className="of-ops-grid"><div className="of-panel"><div className="of-panel-head"><div><span className="of-overline">01 / DELIVERY</span><h3>Transactional outbox</h3></div><span className="of-count-tag">{outbox.length} events</span></div><div className="of-event-list">{outbox.slice(0, 12).map((event) => <div key={event.id} className="of-event-row"><span className="of-event-icon"><Icon name="spark"/></span><span className="of-event-copy"><strong>{event.event_type}</strong><small>{event.aggregate_type} · {event.aggregate_id} · {event.attempts} attempts</small>{event.last_error && <small className="of-error-text">{event.last_error}</small>}</span><Status value={event.status}/></div>)}</div></div><div className="of-panel"><div className="of-panel-head"><div><span className="of-overline">02 / RECOVERY</span><h3>Dead-letter queue</h3></div><span className="of-count-tag">{deadLetters.filter((event) => event.status === "OPEN").length} open</span></div>{deadLetters.length === 0 ? <Empty title="Nothing needs attention" detail="All delivered events are healthy."/> : <div className="of-event-list">{deadLetters.map((event) => <div key={event.id} className="of-event-row"><span className="of-event-copy"><strong>{event.event_type}</strong><small>{event.event_id} · {event.attempts} attempts</small><small className="of-error-text">{event.failure_reason}</small>{event.status === "OPEN" && <button disabled={busy} onClick={() => void retryDeadLetter(event.id)} className="of-mini-button">Retry event <Icon name="arrow-right"/></button>}</span><Status value={event.status}/></div>)}</div>}<div className="of-failed-jobs"><span className="of-overline">FAILED QUEUE JOBS</span>{failedJobs.length === 0 ? <p>No failed jobs recorded.</p> : failedJobs.slice(0, 5).map((job) => <div key={job.id}><strong>{job.queue} · {new Date(job.failed_at).toLocaleString("id-ID")}</strong><small>{job.summary}</small></div>)}</div></div></div></section>}
          {booting && <div className="of-loading">Restoring your session…</div>}
        </section>}
      </div>

      {cartOpen && !isAdmin && <div className="of-drawer-layer"><button type="button" className="of-drawer-scrim" onClick={() => setCartOpen(false)} aria-label="Close order bag"/><aside className="of-drawer" role="dialog" aria-modal="true" aria-label="Your order bag"><div className="of-drawer-head"><div><span className="of-overline">READY WHEN YOU ARE</span><h2>Your order bag <span>{cartCount}</span></h2></div><button type="button" className="of-icon-button" onClick={() => setCartOpen(false)} aria-label="Close order bag"><Icon name="close"/></button></div><div className="of-drawer-body">{cartCount === 0 ? <Empty title="Your bag is empty" detail="Add something from the collection to get started."/> : products.filter((product) => (cart[product.id] ?? 0) > 0).map((product) => <div key={product.id} className="of-cart-line"><div className="of-cart-thumb"><ProductGlyph sku={product.sku}/></div><div className="of-cart-line-copy"><strong>{product.name}</strong><small>{product.sku}</small><span>{money(product.price, product.currency)}</span><div className="of-quantity"><button type="button" onClick={() => changeQuantity(product, -1)} aria-label={`Remove one ${product.name}`}>−</button><b>{cart[product.id]}</b><button type="button" onClick={() => changeQuantity(product, 1)} disabled={cart[product.id] >= product.available_stock} aria-label={`Add one ${product.name}`}>+</button></div></div></div>)}</div><div className="of-drawer-bottom"><div><span>Subtotal</span><strong>{money(cartTotal)}</strong></div><p>Stock is reserved when you check out. Payment uses the local mock provider.</p><button disabled={busy || cartCount === 0} type="button" onClick={() => void checkout()} className="of-button of-button-dark of-button-wide">{busy ? "Reserving…" : "Reserve & checkout"}<Icon name="arrow-right"/></button></div></aside></div>}

      {adjusting && <div className="of-modal-layer"><button type="button" className="of-modal-scrim" onClick={() => setAdjusting(null)} aria-label="Close adjustment"/><form onSubmit={adjustStock} className="of-modal" role="dialog" aria-modal="true" aria-label="Adjust inventory"><div className="of-modal-head"><div><span className="of-overline">STOCK LEDGER</span><h2>Adjust inventory</h2><p>{adjusting.product.name} · {adjusting.warehouse.name}</p></div><button type="button" className="of-icon-button" onClick={() => setAdjusting(null)} aria-label="Close"><Icon name="close"/></button></div><label className="of-field">Adjustment type<select value={adjustType} onChange={(event) => setAdjustType(event.target.value)}><option>INCREASE</option><option>DECREASE</option><option>CORRECTION</option></select></label><label className="of-field">Quantity<input type="number" min="1" value={adjustQuantity} onChange={(event) => setAdjustQuantity(event.target.value)}/></label><label className="of-field">Reason<input value={adjustReason} onChange={(event) => setAdjustReason(event.target.value)}/></label><button disabled={busy} className="of-button of-button-dark of-button-wide">Save adjustment <Icon name="arrow-right"/></button></form></div>}
    </main>
  );
}

function Icon({ name }: { name: "bag" | "logout" | "arrow-up" | "arrow-right" | "spark" | "grid" | "refresh" | "search" | "plus" | "chevron" | "alert" | "check" | "close" }) {
  const paths: Record<typeof name, React.ReactNode> = {
    bag: <><path d="M4 8h16l-1 12H5L4 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></>,
    logout: <><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-6"/></>,
    "arrow-up": <><path d="M6 18 18 6M8 6h10v10"/></>,
    "arrow-right": <><path d="M4 12h16M13 5l7 7-7 7"/></>,
    spark: <><path d="m12 2 2.4 7.6L22 12l-7.6 2.4L12 22l-2.4-7.6L2 12l7.6-2.4L12 2Z"/></>,
    grid: <><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></>,
    refresh: <><path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.8 9A7 7 0 0 1 18 6.5L20 12M4 12l2 5.5A7 7 0 0 0 18.2 15"/></>,
    search: <><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></>,
    plus: <><path d="M12 4v16M4 12h16"/></>,
    chevron: <><path d="m6 9 6 6 6-6"/></>,
    alert: <><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 17h.01"/></>,
    check: <><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></>,
    close: <><path d="M5 5 19 19M19 5 5 19"/></>,
  };
  return <svg className="of-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}

function ProductGlyph({ sku }: { sku: string }) {
  const kind = sku.split("-")[1];
  const artwork: Record<string, React.ReactNode> = {
    MN: <><rect x="20" y="22" width="120" height="72" rx="8" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><path d="M27 85h106M80 94v15M58 109h44" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></>,
    LT: <><path d="M39 103h84M80 101V66M79 67l-21-30M58 37l24-17" stroke="currentColor" strokeWidth="4" strokeLinecap="round"/><path d="M74 16 45 30l-12 28 44-12Z" fill="currentColor" opacity=".14" stroke="currentColor" strokeWidth="2"/><circle cx="80" cy="66" r="6" fill="currentColor" opacity=".4"/></>,
    SP: <><rect x="31" y="31" width="37" height="69" rx="6" fill="currentColor" opacity=".1" stroke="currentColor" strokeWidth="2"/><rect x="92" y="31" width="37" height="69" rx="6" fill="currentColor" opacity=".1" stroke="currentColor" strokeWidth="2"/><circle cx="49.5" cy="70" r="12" stroke="currentColor" strokeWidth="2"/><circle cx="110.5" cy="70" r="12" stroke="currentColor" strokeWidth="2"/><circle cx="49.5" cy="44" r="3" fill="currentColor"/><circle cx="110.5" cy="44" r="3" fill="currentColor"/></>,
    DO: <><path d="m25 60 20-19h75l18 19v29H25V60Z" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><path d="M25 61h113M44 74h16M68 74h15M94 74h12M115 73h8" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/></>,
    HS: <><path d="M38 75V61a42 42 0 0 1 84 0v14" stroke="currentColor" strokeWidth="8" strokeLinecap="round" opacity=".65"/><rect x="28" y="66" width="22" height="34" rx="8" fill="currentColor" opacity=".18" stroke="currentColor" strokeWidth="2"/><rect x="110" y="66" width="22" height="34" rx="8" fill="currentColor" opacity=".18" stroke="currentColor" strokeWidth="2"/></>,
    KC: <><path d="M21 80c4-11 16-20 32-20h54c16 0 28 9 32 20l-11 16H32L21 80Z" fill="currentColor" opacity=".13" stroke="currentColor" strokeWidth="2"/><path d="M36 83h88" stroke="currentColor" strokeWidth="2"/></>,
    ST: <><path d="M31 39h98v48H31V39Z" fill="currentColor" opacity=".1" stroke="currentColor" strokeWidth="2"/><path d="M22 90h116M65 90l-14 17M95 90l14 17M44 108h72" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></>,
    KB: <><rect x="16" y="35" width="128" height="53" rx="9" fill="currentColor" opacity=".1" stroke="currentColor" strokeWidth="2"/><path d="M26 48h108M26 58h108M26 68h108M26 78h108M39 43v40M54 43v40M69 43v40M84 43v40M99 43v40M114 43v40" stroke="currentColor" strokeWidth="2" opacity=".5"/><path d="M49 91h62" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></>,
    MA: <><path d="M77 99h48M100 99V68M100 68 64 48M64 48l-16 24" stroke="currentColor" strokeWidth="5" strokeLinecap="round"/><rect x="21" y="25" width="61" height="48" rx="5" fill="currentColor" opacity=".1" stroke="currentColor" strokeWidth="2"/><circle cx="100" cy="68" r="6" fill="currentColor" opacity=".5"/></>,
    MP: <><rect x="18" y="41" width="124" height="61" rx="16" fill="currentColor" opacity=".13" stroke="currentColor" strokeWidth="2"/><path d="M31 87h98" stroke="currentColor" strokeWidth="2" opacity=".5"/></>,
    CH: <><rect x="54" y="20" width="54" height="61" rx="10" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><path d="M44 73h72v13H44V73ZM80 86v21M58 108h44M50 77l-11 12M110 77l11 12" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></>,
    PO: <><rect x="40" y="23" width="80" height="75" rx="11" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><path d="M54 40h52M54 50h38M65 86h30" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/><circle cx="106" cy="83" r="3" fill="currentColor"/></>,
    AD: <><rect x="41" y="33" width="76" height="59" rx="12" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><path d="M57 25v8M70 25v8M117 62h16M133 62c9 0 10 10 3 15l-10 9" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/><path d="m76 47-9 19h14l-7 14" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"/></>,
    RB: <><path d="M31 63h98v31H31V63Z" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><path d="M48 63 39 27M112 63l9-36M45 83h6M59 83h6M79 84h35" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></>,
    SSD: <><rect x="36" y="24" width="88" height="73" rx="9" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><path d="M52 39h56M52 49h56M52 59h36M52 81h19" stroke="currentColor" strokeWidth="2"/><circle cx="109" cy="82" r="3" fill="currentColor"/></>,
    MC: <><rect x="64" y="17" width="32" height="58" rx="16" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><path d="M52 58a28 28 0 0 0 56 0M80 86v18M56 105h48M72 33h16M72 44h16M72 55h16" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></>,
    CA: <><path d="M46 43c-22 15-19 45 7 52 20 5 36-8 38-23 2-13 18-21 30-13" stroke="currentColor" strokeWidth="5" strokeLinecap="round"/><path d="M35 30h23v22H35V30ZM115 48h22v21h-22V48Z" fill="currentColor" opacity=".14" stroke="currentColor" strokeWidth="2"/><path d="M41 30V21M52 30v-9M120 48V37M132 48V37" stroke="currentColor" strokeWidth="2"/></>,
    HB: <><rect x="30" y="38" width="100" height="50" rx="8" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><path d="M44 57h15v12H44V57ZM68 57h15v12H68V57ZM92 57h15v12H92V57ZM115 58h7v11h-7V58Z" stroke="currentColor" strokeWidth="2"/><path d="M29 62H14" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></>,
    WC: <><rect x="38" y="35" width="84" height="50" rx="16" fill="currentColor" opacity=".12" stroke="currentColor" strokeWidth="2"/><circle cx="80" cy="60" r="16" stroke="currentColor" strokeWidth="3"/><circle cx="80" cy="60" r="7" fill="currentColor" opacity=".4"/><path d="M80 85v17M58 103h44" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></>,
    MS: <><path d="M80 22c-21 0-35 17-35 40v21c0 16 14 27 35 27s35-11 35-27V62c0-23-14-40-35-40Z" fill="currentColor" opacity=".1" stroke="currentColor" strokeWidth="2"/><path d="M80 23v41M67 62h26" stroke="currentColor" strokeWidth="2"/><rect x="76" y="42" width="8" height="14" rx="4" fill="currentColor" opacity=".5"/></>,
  };
  return <svg className="of-glyph" viewBox="0 0 160 120" fill="none" aria-hidden="true">{artwork[kind] ?? artwork.SSD}</svg>;
}

function Metric({ label: title, value, detail }: { label: string; value: string; detail: string }) {
  return <div className="of-metric"><span>{title}</span><strong>{value}</strong><small>{detail}</small></div>;
}

function Status({ value }: { value: string }) {
  const tone = value === "PAID" || value === "SUCCEEDED" || value === "PUBLISHED" || value === "RETRIED" || value === "COMMITTED" ? "good" : value === "FAILED" || value === "OPEN" || value === "EXPIRED" ? "bad" : value === "CANCELLED" ? "neutral" : "waiting";
  return <span className={`of-status of-status-${tone}`}><span/>{label(value)}</span>;
}

function Empty({ title, detail }: { title: string; detail: string }) {
  return <div className="of-empty"><span><Icon name="spark"/></span><strong>{title}</strong><p>{detail}</p></div>;
}

function AdminOverview({ orders, inventory, outbox, deadLetters, onNavigate }: { orders: Order[]; inventory: Inventory[]; outbox: Outbox[]; deadLetters: DeadLetter[]; onNavigate: (tab: string) => void }) {
  const paid = orders.filter((order) => order.status === "PAID").length;
  const pending = orders.filter((order) => order.status === "PENDING_PAYMENT").length;
  const openLetters = deadLetters.filter((item) => item.status === "OPEN").length;
  const chart = [{ label: "Paid", value: paid, tone: "paid" }, { label: "Pending", value: pending, tone: "pending" }, { label: "Closed", value: orders.filter((order) => ["CANCELLED", "EXPIRED"].includes(order.status)).length, tone: "closed" }];
  const max = Math.max(1, ...chart.map((item) => item.value));
  return <div className="of-admin-home"><div className="of-admin-intro"><div><span className="of-overline">YOUR BUSINESS, IN FOCUS</span><h2>Everything is moving.<br/><em>Stay in control.</em></h2></div><div className="of-admin-intro-side"><span className="of-live-dot is-on"/> All systems operational <small>Stock, payments and events are in sync.</small></div></div><div className="of-stats-strip is-admin"><Metric label="Total orders" value={String(orders.length)} detail={`${pending} awaiting payment`}/><Metric label="Paid orders" value={String(paid)} detail="Stock committed"/><Metric label="Available units" value={String(inventory.reduce((total, item) => total + item.available, 0))} detail="Across warehouses"/><Metric label="Open dead letters" value={String(openLetters)} detail={`${outbox.filter((event) => event.status === "PENDING").length} events pending`}/></div><div className="of-admin-grid"><section className="of-panel"><div className="of-panel-head"><div><span className="of-overline">LIVE ACTIVITY</span><h3>Recent orders</h3></div><button type="button" className="of-text-action" onClick={() => onNavigate("orders")}>View all <Icon name="arrow-right"/></button></div><div className="of-recent-list">{orders.slice(0, 6).map((order) => <div key={order.id}><span className="of-recent-icon"><Icon name="bag"/></span><span><strong>{order.order_number}</strong><small>{order.items.length} item{order.items.length === 1 ? "" : "s"} · {money(order.total, order.currency)}</small></span><Status value={order.status}/></div>)}</div></section><section className="of-panel of-insights"><div className="of-panel-head"><div><span className="of-overline">AT A GLANCE</span><h3>Order mix</h3></div><span className="of-count-tag">{orders.length} total</span></div><div className="of-bar-chart">{chart.map((item) => <div key={item.label} className="of-bar-row"><div><span>{item.label}</span><strong>{item.value}</strong></div><div className="of-bar-track"><span className={`of-bar-${item.tone}`} style={{ width: `${Math.max(item.value > 0 ? 6 : 0, item.value / max * 100)}%` }}/></div></div>)}</div><div className="of-system-note"><span className="of-spark"><Icon name="spark"/></span><div><strong>Built to stay consistent.</strong><p>Reservations, webhooks and event delivery stay traceable as orders move.</p></div></div></section></div></div>;
}
