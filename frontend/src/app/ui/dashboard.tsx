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
  const [adjusting, setAdjusting] = useState<Inventory | null>(null);
  const [adjustType, setAdjustType] = useState("INCREASE");
  const [adjustQuantity, setAdjustQuantity] = useState("1");
  const [adjustReason, setAdjustReason] = useState("Demo warehouse correction");
  const [liveConnected, setLiveConnected] = useState(false);

  const isAdmin = user?.role === "ADMIN";
  const filteredProducts = useMemo(() => products.filter((product) => `${product.name} ${product.sku}`.toLowerCase().includes(search.toLowerCase())), [products, search]);
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

  async function checkout() {
    if (!user || user.role !== "CUSTOMER") return;
    setBusy(true); setError("");
    try {
      const created = await api<Order>("/orders", token, { items: Object.entries(cart).filter(([, quantity]) => quantity > 0).map(([product_id, quantity]) => ({ product_id: Number(product_id), quantity })) }, { "Idempotency-Key": crypto.randomUUID() });
      await api<Payment>(`/orders/${created.data.id}/payments`, token, {}, { "Idempotency-Key": crypto.randomUUID() });
      setCart({}); setTab("orders"); setNotice(`Order ${created.data.order_number} was reserved. Complete the mock payment below.`);
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

  const menu = isAdmin
    ? [["overview", "Overview"], ["orders", "Orders"], ["payments", "Payments"], ["inventory", "Inventory"], ["operations", "Event pipeline"]]
    : [["overview", "Shop"], ["orders", "My orders"]];

  return (
    <main className="min-h-screen bg-[#f5f7fb] text-slate-900">
      <div className="mx-auto min-h-screen max-w-[1440px] lg:grid lg:grid-cols-[252px_1fr]">
        <aside className="flex flex-col border-b border-slate-200 bg-white px-5 py-5 lg:min-h-screen lg:border-b-0 lg:border-r lg:px-6 lg:py-8">
          <Link href="/" className="flex items-center gap-3">
            <span className="grid size-10 place-items-center rounded-xl bg-indigo-600 text-sm font-black text-white shadow-lg shadow-indigo-200">OF</span>
            <span><span className="block text-lg font-bold tracking-tight">OrderForge</span><span className="block text-[11px] font-semibold uppercase tracking-[.16em] text-slate-400">Commerce operations</span></span>
          </Link>
          <div className="mt-8 flex gap-2 overflow-x-auto lg:mt-12 lg:flex-col">
            {menu.map(([key, title]) => <button key={key} onClick={() => setTab(key)} className={`rounded-xl px-4 py-3 text-left text-sm font-semibold transition ${tab === key ? "bg-indigo-50 text-indigo-700" : "text-slate-500 hover:bg-slate-50 hover:text-slate-900"}`}><span className="mr-3 text-base">{key === "overview" ? "⌂" : key === "orders" ? "▤" : key === "inventory" ? "◫" : "⌁"}</span>{title}</button>)}
          </div>
          <div className="mt-auto hidden rounded-2xl bg-slate-950 p-4 text-white lg:block">
            <div className="flex items-center gap-2"><span className={`size-2 rounded-full ${liveConnected ? "bg-emerald-400" : "bg-amber-400"}`} /><span className="text-xs font-semibold">{liveConnected ? "Live updates connected" : "Realtime ready"}</span></div>
            <p className="mt-3 text-xs leading-5 text-slate-400">Private order channels keep payment and fulfillment status in sync.</p>
          </div>
        </aside>

        <section className="min-w-0 px-5 py-6 sm:px-8 lg:px-10 lg:py-9">
          <header className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-6">
            <div><p className="text-xs font-bold uppercase tracking-[.18em] text-indigo-600">{isAdmin ? "Operations console" : "Customer workspace"}</p><h1 className="mt-1 text-2xl font-bold tracking-tight">{tab === "overview" ? (isAdmin ? "Operations overview" : "Find your next favorite") : menu.find(([key]) => key === tab)?.[1]}</h1></div>
            <div className="flex items-center gap-3">
              {user ? <><div className="hidden text-right sm:block"><p className="text-sm font-semibold">{user.name}</p><p className="text-xs text-slate-400">{user.role === "ADMIN" ? "Operations admin" : user.email}</p></div><button onClick={() => void signOut()} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600">Sign out</button></> : <a href="http://127.0.0.1:8000/health/ready" target="_blank" rel="noreferrer" className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600">API status ↗</a>}
            </div>
          </header>

          {!user && <section className="grid gap-8 py-10 xl:grid-cols-[1fr_420px]">
            <div className="rounded-[28px] bg-[#131a32] p-8 text-white sm:p-12"><p className="inline-flex rounded-full border border-indigo-300/20 bg-indigo-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[.16em] text-indigo-200">Transactional commerce platform</p><h2 className="mt-7 max-w-2xl text-4xl font-bold leading-tight sm:text-6xl">Orders stay right, <span className="text-indigo-300">even under pressure.</span></h2><p className="mt-5 max-w-xl text-base leading-7 text-slate-300">Atomic reservations, safe retries, and payment events come together in one live operations workspace.</p><div className="mt-8 flex flex-wrap gap-2">{["Laravel 13", "PostgreSQL", "Redis", "RabbitMQ", "Reverb"].map((item) => <span key={item} className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-200">{item}</span>)}</div></div>
            <form onSubmit={signIn} className="rounded-[28px] border border-slate-200 bg-white p-7 shadow-sm sm:p-9"><p className="text-sm font-bold text-indigo-600">Welcome back</p><h2 className="mt-1 text-2xl font-bold">Sign in to OrderForge</h2><p className="mt-2 text-sm leading-6 text-slate-500">Choose a demo account to explore the customer flow or operations console.</p><label className="mt-7 block text-xs font-bold uppercase tracking-wider text-slate-500">Email<input autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm normal-case tracking-normal outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-50" /></label><label className="mt-4 block text-xs font-bold uppercase tracking-wider text-slate-500">Password<input type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 text-sm normal-case tracking-normal outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-50" /></label><button disabled={busy} className="mt-6 w-full rounded-xl bg-indigo-600 px-4 py-3.5 text-sm font-bold text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-700 disabled:opacity-50">{busy ? "Signing in…" : "Sign in"}</button><div className="mt-4 grid grid-cols-2 gap-2"><button type="button" onClick={() => { setEmail("customer1@orderforge.test"); setPassword("OrderForgeLocal2026!"); }} className="rounded-lg bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100">Use customer demo</button><button type="button" onClick={() => { setEmail("admin@orderforge.test"); setPassword("OrderForgeLocal2026!"); }} className="rounded-lg bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100">Use admin demo</button></div></form>
          </section>}

          {error && <div role="alert" className="my-5 flex items-start justify-between gap-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><span>{error}</span><button onClick={() => setError("")} aria-label="Dismiss" className="font-bold">×</button></div>}
          {notice && <div role="status" className="my-5 flex items-start justify-between gap-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><span>{notice}</span><button onClick={() => setNotice("")} aria-label="Dismiss" className="font-bold">×</button></div>}

          {user && tab === "overview" && (isAdmin ? <AdminOverview orders={orders} inventory={inventory} outbox={outbox} deadLetters={deadLetters} /> : <>
            <div className="grid gap-4 py-7 sm:grid-cols-3"><Metric label="Available products" value={String(products.length)} detail="Ready to order"/><Metric label="Active orders" value={String(orders.filter((order) => order.status === "PENDING_PAYMENT").length)} detail="Awaiting payment"/><Metric label="Orders completed" value={String(orders.filter((order) => order.status === "PAID").length)} detail="Payment confirmed"/></div>
            <div className="flex flex-wrap items-end justify-between gap-4 pb-5"><div><h2 className="text-lg font-bold">Explore the catalog</h2><p className="mt-1 text-sm text-slate-500">Stock availability refreshes after every committed order.</p></div><div className="flex gap-2"><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search products or SKU" className="w-56 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-indigo-400"/><button onClick={() => void refreshProducts()} className="rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600">Refresh</button></div></div>
            <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">{filteredProducts.map((product, index) => <article key={product.id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div className={`mb-5 grid h-28 place-items-center rounded-xl ${["bg-indigo-50 text-indigo-500", "bg-cyan-50 text-cyan-600", "bg-amber-50 text-amber-600"][index % 3]}`}><span className="text-4xl">{["⌨", "◉", "▱"][index % 3]}</span></div><div className="flex items-start justify-between gap-3"><div><p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">{product.sku}</p><h3 className="mt-1 font-bold">{product.name}</h3></div><span className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${product.available_stock > 0 ? "bg-emerald-50 text-emerald-700" : "bg-rose-50 text-rose-700"}`}>{product.available_stock} available</span></div><p className="mt-2 min-h-10 text-xs leading-5 text-slate-500">{product.description ?? "Reliable everyday hardware, ready to ship."}</p><div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4"><span className="font-bold">{money(product.price, product.currency)}</span>{!isAdmin && <button disabled={product.available_stock < 1} onClick={() => addToCart(product)} className="rounded-lg bg-indigo-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-indigo-700 disabled:bg-slate-200 disabled:text-slate-400">Add to order +</button>}</div>{(cart[product.id] ?? 0) > 0 && <p className="mt-2 text-xs font-semibold text-indigo-700">{cart[product.id]} in your order</p>}</article>)}</div>
            {cartCount > 0 && <div className="fixed inset-x-4 bottom-4 z-20 mx-auto flex max-w-3xl items-center justify-between gap-4 rounded-2xl bg-slate-950 p-4 text-white shadow-2xl sm:inset-x-auto sm:right-8"><div><p className="text-xs text-slate-400">{cartCount} item{cartCount === 1 ? "" : "s"} in order</p><p className="font-bold">{money(cartTotal)}</p></div><button onClick={() => void checkout()} disabled={busy} className="rounded-xl bg-indigo-500 px-5 py-3 text-sm font-bold hover:bg-indigo-400 disabled:opacity-50">{busy ? "Reserving…" : "Reserve & checkout →"}</button></div>}
          </>)}

          {user && tab === "orders" && <div className="space-y-4 py-7">{orders.length === 0 ? <Empty title="No orders yet" detail={isAdmin ? "Customer orders will appear here." : "Your orders and payment updates will appear here."}/> : orders.map((order) => <article key={order.id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs font-semibold text-slate-400">{order.order_number}</p><h2 className="mt-1 text-lg font-bold">{money(order.total, order.currency)}</h2><p className="mt-1 text-xs text-slate-400">Created {new Date(order.created_at).toLocaleString("id-ID")}</p></div><Status value={order.status}/></div><div className="mt-4 divide-y divide-slate-100 rounded-xl bg-slate-50 px-4">{order.items.map((item, index) => <div key={`${item.product_id}-${index}`} className="flex items-center justify-between gap-4 py-3 text-sm"><span><span className="font-semibold">{item.name}</span><span className="ml-2 text-xs text-slate-400">× {item.quantity}</span></span><span className="font-semibold">{money(item.subtotal, order.currency)}</span></div>)}</div><div className="mt-4 flex flex-wrap items-center justify-between gap-3">{order.payment ? <p className="text-xs text-slate-500">Payment <span className="font-semibold text-slate-700">{order.payment.payment_reference}</span> · <Status value={order.payment.status}/></p> : <p className="text-xs text-slate-500">Reservation expires {order.expires_at ? new Date(order.expires_at).toLocaleString("id-ID") : "soon"}</p>}{!isAdmin && order.status === "PENDING_PAYMENT" && <div className="flex gap-2">{!order.payment && <button disabled={busy} onClick={() => void startPayment(order.id)} className="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white">Create payment</button>}{order.payment?.status === "PENDING" && <><button disabled={busy} onClick={() => void simulatePayment(order.payment!.id, true)} className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">Simulate success</button><button disabled={busy} onClick={() => void simulatePayment(order.payment!.id, false)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600">Simulate failure</button><button disabled={busy} onClick={() => void simulateTimeout(order.payment!.id)} className="rounded-lg border border-amber-200 px-3 py-2 text-xs font-bold text-amber-700">Simulate timeout</button></>}<button disabled={busy} onClick={() => void cancelOrder(order.id)} className="rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-600">Cancel</button></div>}</div></article>)}</div>}

          {user && isAdmin && tab === "payments" && <section className="py-7"><div className="mb-5"><h2 className="text-lg font-bold">Payment activity</h2><p className="mt-1 text-sm text-slate-500">Mock provider results arrive through signed, deduplicated webhooks.</p></div><div className="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div className="overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-400"><tr><th className="px-5 py-4">Reference</th><th className="px-5 py-4">Order</th><th className="px-5 py-4">Amount</th><th className="px-5 py-4">Provider</th><th className="px-5 py-4">Status</th></tr></thead><tbody className="divide-y divide-slate-100">{payments.map((payment) => <tr key={payment.id}><td className="px-5 py-4 font-semibold">{payment.payment_reference}</td><td className="px-5 py-4 text-slate-500">#{payment.order_id}</td><td className="px-5 py-4">{money(payment.amount, payment.currency)}</td><td className="px-5 py-4 text-slate-500">Mock provider</td><td className="px-5 py-4"><Status value={payment.status}/></td></tr>)}</tbody></table></div></div></section>}

          {user && isAdmin && tab === "inventory" && <section className="py-7"><div className="mb-5 flex items-end justify-between"><div><h2 className="text-lg font-bold">Warehouse inventory</h2><p className="mt-1 text-sm text-slate-500">Adjustments are audited and preserve active reservations.</p></div><button onClick={() => void refreshAdmin()} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold">Refresh</button></div><div className="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left text-sm"><thead className="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-400"><tr><th className="px-5 py-4">Product</th><th className="px-5 py-4">Warehouse</th><th className="px-5 py-4">On hand</th><th className="px-5 py-4">Reserved</th><th className="px-5 py-4">Available</th><th className="px-5 py-4"/></tr></thead><tbody className="divide-y divide-slate-100">{inventory.map((row) => <tr key={row.id}><td className="px-5 py-4"><span className="font-semibold">{row.product.name}</span><span className="ml-2 text-xs text-slate-400">{row.product.sku}</span></td><td className="px-5 py-4 text-slate-500">{row.warehouse.name}</td><td className="px-5 py-4">{row.on_hand}</td><td className="px-5 py-4">{row.reserved}</td><td className="px-5 py-4 font-bold text-indigo-700">{row.available}</td><td className="px-5 py-4 text-right"><button onClick={() => setAdjusting(row)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold">Adjust</button></td></tr>)}</tbody></table></div></div></section>}

          {user && isAdmin && tab === "operations" && <section className="grid gap-5 py-7 xl:grid-cols-2"><div className="rounded-2xl border border-slate-200 bg-white p-5"><div className="flex items-center justify-between"><div><h2 className="font-bold">Transactional outbox</h2><p className="mt-1 text-xs text-slate-500">Domain events waiting for durable delivery.</p></div><span className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700">{outbox.length} events</span></div><div className="mt-5 space-y-2">{outbox.slice(0, 10).map((event) => <div key={event.id} className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-3"><div className="min-w-0"><p className="truncate text-sm font-semibold">{event.event_type}</p><p className="mt-1 truncate text-[11px] text-slate-400">{event.aggregate_type} · {event.aggregate_id} · {event.attempts} attempts</p>{event.last_error && <p className="mt-1 truncate text-[11px] text-rose-600">{event.last_error}</p>}</div><Status value={event.status}/></div>)}</div></div><div className="rounded-2xl border border-slate-200 bg-white p-5"><div className="flex items-center justify-between"><div><h2 className="font-bold">Dead-letter queue</h2><p className="mt-1 text-xs text-slate-500">Failures stay visible until an operator retries them.</p></div><span className="rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-700">{deadLetters.filter((event) => event.status === "OPEN").length} open</span></div><div className="mt-5 space-y-2">{deadLetters.length === 0 ? <Empty title="No dead letters" detail="All delivered events are healthy."/> : deadLetters.map((event) => <div key={event.id} className="rounded-xl bg-slate-50 p-3"><div className="flex items-center justify-between gap-3"><div><p className="text-sm font-semibold">{event.event_type}</p><p className="mt-1 text-[11px] text-slate-400">{event.event_id} · {event.attempts} attempts</p></div><Status value={event.status}/></div><p className="mt-2 text-xs text-rose-700">{event.failure_reason}</p>{event.status === "OPEN" && <button disabled={busy} onClick={() => void retryDeadLetter(event.id)} className="mt-3 rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white">Retry event</button>}</div>)}</div><h3 className="mt-7 border-t border-slate-100 pt-5 text-sm font-bold">Failed queue jobs</h3><div className="mt-3 space-y-2">{failedJobs.length === 0 ? <p className="text-xs text-slate-400">No failed jobs recorded.</p> : failedJobs.slice(0, 5).map((job) => <div key={job.id} className="rounded-lg bg-rose-50 px-3 py-2"><p className="text-xs font-semibold text-rose-800">{job.queue} · {new Date(job.failed_at).toLocaleString("id-ID")}</p><p className="mt-1 truncate text-[11px] text-rose-700">{job.summary}</p></div>)}</div></div></section>}

          {booting && <div className="py-16 text-center text-sm text-slate-400">Restoring your secure session…</div>}
        </section>
      </div>

      {adjusting && <div className="fixed inset-0 z-30 grid place-items-center bg-slate-950/40 p-4" role="presentation"><form onSubmit={adjustStock} className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><div className="flex items-start justify-between"><div><p className="text-xs font-bold uppercase tracking-wider text-indigo-600">Stock ledger</p><h2 className="mt-1 text-xl font-bold">Adjust inventory</h2><p className="mt-1 text-sm text-slate-500">{adjusting.product.name} · {adjusting.warehouse.name}</p></div><button type="button" onClick={() => setAdjusting(null)} className="text-xl text-slate-400">×</button></div><label className="mt-6 block text-xs font-bold uppercase tracking-wider text-slate-500">Adjustment type<select value={adjustType} onChange={(event) => setAdjustType(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-3 text-sm normal-case"><option>INCREASE</option><option>DECREASE</option><option>CORRECTION</option></select></label><label className="mt-4 block text-xs font-bold uppercase tracking-wider text-slate-500">Quantity<input type="number" min="1" value={adjustQuantity} onChange={(event) => setAdjustQuantity(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-3 text-sm normal-case"/></label><label className="mt-4 block text-xs font-bold uppercase tracking-wider text-slate-500">Reason<input value={adjustReason} onChange={(event) => setAdjustReason(event.target.value)} className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-3 text-sm normal-case"/></label><button disabled={busy} className="mt-6 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white">Save adjustment</button></form></div>}
    </main>
  );
}

function Metric({ label: title, value, detail }: { label: string; value: string; detail: string }) {
  return <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p className="text-xs font-semibold text-slate-500">{title}</p><p className="mt-2 text-3xl font-bold tracking-tight">{value}</p><p className="mt-1 text-xs text-slate-400">{detail}</p></div>;
}

function Status({ value }: { value: string }) {
  const tone = value === "PAID" || value === "SUCCEEDED" || value === "PUBLISHED" || value === "RETRIED" || value === "COMMITTED" ? "bg-emerald-50 text-emerald-700" : value === "FAILED" || value === "OPEN" || value === "EXPIRED" ? "bg-rose-50 text-rose-700" : value === "CANCELLED" ? "bg-slate-100 text-slate-600" : "bg-amber-50 text-amber-700";
  return <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ${tone}`}>{label(value)}</span>;
}

function Empty({ title, detail }: { title: string; detail: string }) {
  return <div className="rounded-xl border border-dashed border-slate-200 px-5 py-8 text-center"><p className="text-sm font-semibold text-slate-700">{title}</p><p className="mt-1 text-xs text-slate-400">{detail}</p></div>;
}

function AdminOverview({ orders, inventory, outbox, deadLetters }: { orders: Order[]; inventory: Inventory[]; outbox: Outbox[]; deadLetters: DeadLetter[] }) {
  const paid = orders.filter((order) => order.status === "PAID").length;
  const pending = orders.filter((order) => order.status === "PENDING_PAYMENT").length;
  return <div className="py-7"><div className="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4"><Metric label="Orders today" value={String(orders.length)} detail={`${pending} awaiting payment`}/><Metric label="Paid orders" value={String(paid)} detail="Stock committed"/><Metric label="Available units" value={String(inventory.reduce((total, item) => total + item.available, 0))} detail="Across all warehouses"/><Metric label="Open dead letters" value={String(deadLetters.filter((item) => item.status === "OPEN").length)} detail={`${outbox.filter((event) => event.status === "PENDING").length} outbox events pending`}/></div><div className="mt-6 grid gap-5 xl:grid-cols-[1.2fr_0.8fr]"><div className="rounded-2xl border border-slate-200 bg-white p-5"><div className="flex items-center justify-between"><div><h2 className="font-bold">Recent orders</h2><p className="mt-1 text-xs text-slate-500">Live updates arrive on private order channels.</p></div><span className="text-xs font-semibold text-slate-400">{orders.length} total</span></div><div className="mt-4 space-y-2">{orders.slice(0, 8).map((order) => <div key={order.id} className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3"><div><p className="text-sm font-semibold">{order.order_number}</p><p className="mt-1 text-xs text-slate-400">{order.items.length} line items · {money(order.total, order.currency)}</p></div><Status value={order.status}/></div>)}</div></div><div className="rounded-2xl bg-indigo-600 p-6 text-white"><p className="text-xs font-bold uppercase tracking-[.16em] text-indigo-200">System signals</p><h2 className="mt-2 text-2xl font-bold">Reliable by design</h2><div className="mt-6 space-y-3">{[["PostgreSQL", "Transactional stock ledger"], ["Redis", "Catalog cache and rate limits"], ["RabbitMQ", "Outbox delivery and retries"], ["Reverb", "Private order status updates"]].map(([name, detail]) => <div key={name} className="flex items-center justify-between gap-4 border-b border-white/10 pb-3"><div><p className="text-sm font-semibold">{name}</p><p className="mt-1 text-xs text-indigo-100/70">{detail}</p></div><span className="size-2 rounded-full bg-emerald-300"/></div>)}</div></div></div></div>;
}
