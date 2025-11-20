<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rosa Mexicano POS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: light dark;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            flex-direction: column;
            color: #0f172a;
            background: radial-gradient(circle at top, #f5f3ff, #fff);
        }

        header {
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand h1 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .brand span {
            color: #475569;
            font-size: 0.95rem;
        }

        .lang-toggle {
            display: inline-flex;
            align-items: center;
            background: #f1f5f9;
            border-radius: 999px;
            padding: 0.2rem;
            gap: 0.2rem;
        }

        .lang-toggle__btn {
            border: none;
            background: transparent;
            padding: 0.35rem 0.9rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #475569;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .lang-toggle__btn--active {
            background: #fff;
            color: #ec4899;
            box-shadow: 0 4px 12px rgba(236, 72, 153, 0.25);
        }

        .hero {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 2rem;
        }

        .hero__badge {
            background: rgba(244, 63, 94, 0.08);
            color: #be123c;
            padding: 0.35rem 0.9rem;
            border-radius: 999px;
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .hero h2 {
            font-size: clamp(2.25rem, 5vw, 3.25rem);
            margin: 0 0 1rem;
            line-height: 1.15;
            color: #111827;
        }

        .hero p {
            max-width: 680px;
            font-size: 1.1rem;
            line-height: 1.8;
            color: #475569;
            margin: 0 auto 2rem;
        }

        .hero__image {
            margin: 2rem 0;
            width: min(420px, 80vw);
            border-radius: 2rem;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(15, 23, 42, 0.15);
            border: 2px solid rgba(236, 72, 153, 0.15);
        }

        .hero__image img {
            width: 100%;
            display: block;
        }

        .hero__cta {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            background: linear-gradient(135deg, #ec4899, #f43f5e);
            color: #fff;
            padding: 0.95rem 2rem;
            border-radius: 999px;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 15px 30px rgba(250, 82, 160, 0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .hero__cta:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 35px rgba(244, 63, 94, 0.35);
        }

        .features {
            display: grid;
            grid-template-columns: repeat(6, minmax(180px, 1fr));
            gap: 1.5rem;
            width: 100%;
            max-width: 1000px;
            margin-top: 3rem;
            overflow-x: auto;
        }

        .feature {
            background: linear-gradient(160deg, #fff, rgba(248, 250, 252, 0.8));
            border-radius: 1.25rem;
            padding: 1.5rem;
            box-shadow: 0 15px 45px rgba(15, 23, 42, 0.08);
            text-align: left;
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .feature:nth-child(1) {
            background: linear-gradient(160deg, #fff, rgba(249, 168, 212, 0.3));
            border-color: rgba(236, 72, 153, 0.4);
        }

        .feature:nth-child(2) {
            background: linear-gradient(160deg, #fff, rgba(129, 140, 248, 0.25));
            border-color: rgba(129, 140, 248, 0.5);
        }

        .feature:nth-child(3) {
            background: linear-gradient(160deg, #fff, rgba(45, 212, 191, 0.25));
            border-color: rgba(16, 185, 129, 0.45);
        }

        .feature:nth-child(4) {
            background: linear-gradient(160deg, #fff, rgba(253, 186, 116, 0.3));
            border-color: rgba(249, 115, 22, 0.45);
        }

        .feature:nth-child(5) {
            background: linear-gradient(160deg, #fff, rgba(96, 165, 250, 0.3));
            border-color: rgba(59, 130, 246, 0.45);
        }

        .feature h3 {
            margin: 0 0 0.5rem;
            font-size: 1.1rem;
            color: #0f172a;
        }

        .feature p {
            margin: 0;
            line-height: 1.6;
            color: #475569;
        }

        .edge-text {
            font-size: 0.95rem;
            color: #475569;
            margin-top: 1.5rem;
            text-align: left;
        }

        .dataflow {
            width: 100%;
            max-width: 1100px;
            margin-top: 3rem;
            padding: 2.5rem;
            border-radius: 1.5rem;
            background: #fff;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.08);
        }

        .dataflow h3 {
            margin: 0 0 1.5rem;
            font-size: 1.5rem;
            color: #0f172a;
        }

        .dataflow__canvas {
            width: 100%;
            overflow-x: auto;
        }

        .dataflow__flow {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            position: relative;
        }

        .dataflow__flow::before {
            content: "";
            position: absolute;
            top: 45%;
            left: 5%;
            right: 5%;
            height: 3px;
            background: linear-gradient(90deg, rgba(236, 72, 153, 0.15), rgba(14, 165, 233, 0.35));
            z-index: 0;
        }

        .dataflow__stage {
            background: linear-gradient(160deg, #fff, #fdf2f8);
            border-radius: 1.25rem;
            padding: 1.5rem;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
            position: relative;
            z-index: 1;
        }

        .dataflow__stage:nth-child(2) {
            background: linear-gradient(160deg, #fff, #e0f2fe);
        }

        .dataflow__stage:nth-child(3) {
            background: linear-gradient(160deg, #fff, #fef3c7);
        }

        .dataflow__stage:nth-child(4) {
            background: linear-gradient(160deg, #fff, #dcfce7);
        }

        .dataflow__icon {
            font-size: 1.75rem;
            margin-bottom: 0.75rem;
        }

        .dataflow__stage h4 {
            margin: 0 0 0.5rem;
            font-size: 1.1rem;
            color: #0f172a;
        }

        .dataflow__stage p {
            margin: 0;
            color: #475569;
            line-height: 1.6;
        }

        footer {
            padding: 2rem;
            text-align: center;
            font-size: 0.95rem;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <header>
        <div class="brand">
            <h1 data-en="Rosa Mexicano POS" data-es="Rosa Mexicano POS">Rosa Mexicano POS</h1>
            <span data-en="Unified retail control" data-es="Control minorista unificado">Unified retail control</span>
        </div>
        <div class="lang-toggle" role="group" aria-label="Language toggle">
            <button type="button" class="lang-toggle__btn lang-toggle__btn--active" data-lang-btn="en">EN</button>
            <button type="button" class="lang-toggle__btn" data-lang-btn="es">ES</button>
        </div>
    </header>
    <main class="hero">
        <span class="hero__badge" data-en="Trusted by modern multibrand retailers" data-es="Confiado por minoristas multimarcas">Trusted by modern multibrand retailers</span>
        <h2 data-en="Every product, provider, and sale unified in a single POS." data-es="Cada producto, proveedor y venta unificados en un solo POS.">Every product, provider, and sale unified in a single POS.</h2>
        <p data-en="Rosa Mexicano POS gives physical stores a real-time command center—centralize catalogs from every supplier, keep inventory synced across channels, and close sales faster with automation built for busy retail teams. Managers stay ahead of demand while finance trusts the numbers." data-es="Rosa Mexicano POS ofrece a las tiendas físicas un centro de mando en tiempo real: centraliza catálogos de cada proveedor, mantiene el inventario sincronizado en todos los canales y acelera las ventas con automatización pensada para equipos minoristas. Los gerentes se adelantan a la demanda mientras finanzas confía en los números.">
            Rosa Mexicano POS gives physical stores a real-time command center—centralize catalogs from every supplier,
            keep inventory synced across channels, and close sales faster with automation built for busy retail teams.
            Managers stay ahead of demand while finance trusts the numbers.
        </p>
        <a class="hero__cta" href="/auth/login">
            <span class="hero__cta-text" data-en="Enter the platform" data-es="Entrar a la plataforma">Enter the platform</span>
            <span aria-hidden="true">→</span>
        </a>
        <div class="hero__image">
            <img src="{{ asset('images/themes/logorm.png') }}" alt="Rosa Mexicano POS brand mark">
        </div>

        <section class="features">
            <article class="feature">
                <h3 data-en="Unified catalog" data-es="Catálogo unificado">Unified catalog</h3>
                <p data-en="Create one source of truth for products from every provider, complete with pricing tiers and bundles." data-es="Crea una sola fuente de verdad para productos de cada proveedor, con niveles de precios y paquetes.">Create one source of truth for products from every provider, complete with pricing tiers and bundles.</p>
            </article>
            <article class="feature">
                <h3 data-en="Automated close-outs" data-es="Cierres automatizados">Automated close-outs</h3>
                <p data-en="Reconcile cashboxes, flag discrepancies, and publish end-of-day reports without spreadsheets." data-es="Cuadra cajas, detecta discrepancias y publica reportes del día sin hojas de cálculo.">Reconcile cashboxes, flag discrepancies, and publish end-of-day reports without spreadsheets.</p>
            </article>
            <article class="feature">
                <h3 data-en="Live analytics" data-es="Analítica en vivo">Live analytics</h3>
                <p data-en="Drill into margin heatmaps, cohort comparisons, and daily KPI snapshots to guide promotions and reorders." data-es="Profundiza en mapas de margen, comparativos de cohortes y KPIs diarios para guiar promociones y reposiciones.">Drill into margin heatmaps, cohort comparisons, and daily KPI snapshots to guide promotions and reorders.</p>
            </article>
            <article class="feature">
                <h3 data-en="Promotions & extras" data-es="Promociones y extras">Promotions & extras</h3>
                <p data-en="Configure bundles, discounts, and extra charges per provider or channel with precise audit trails." data-es="Configura paquetes, descuentos y cargos extra por proveedor o canal con trazabilidad precisa.">Configure bundles, discounts, and extra charges per provider or channel with precise audit trails.</p>
            </article>
            <article class="feature">
                <h3 data-en="Provider portal" data-es="Portal de proveedores">Provider portal</h3>
                <p data-en="Give each proveedor secure self-service access to inventory levels, sales, invoices, and payments." data-es="Da a cada proveedor acceso seguro a inventario, ventas, facturas y pagos en autoservicio.">Give each proveedor secure self-service access to inventory levels, sales, invoices, and payments.</p>
            </article>
        </section>

        <section class="dataflow" aria-label="System data flow diagram">
            <h3 data-en="How the platform keeps control" data-es="Cómo la plataforma mantiene el control">How the platform keeps control</h3>
            <div class="dataflow__canvas">
                <div class="dataflow__flow">
                    <article class="dataflow__stage">
                        <div class="dataflow__icon">🔗</div>
                        <h4 data-en="Provider onboarding" data-es="Onboarding de proveedores">Provider onboarding</h4>
                        <p data-en="Suppliers sync catalogs, pricing, and compliance docs through their secure portal." data-es="Los proveedores sincronizan catálogos, precios y documentos de cumplimiento en su portal seguro.">Suppliers sync catalogs, pricing, and compliance docs through their secure portal.</p>
                    </article>
                    <article class="dataflow__stage">
                        <div class="dataflow__icon">🛍️</div>
                        <h4 data-en="Unified retail POS" data-es="POS minorista unificado">Unified retail POS</h4>
                        <p data-en="Teams sell, manage cashboxes, apply promotions, and trigger extra charges in one place." data-es="Los equipos venden, controlan cajas, aplican promociones y generan cargos extra en un solo lugar.">Teams sell, manage cashboxes, apply promotions, and trigger extra charges in one place.</p>
                    </article>
                    <article class="dataflow__stage">
                        <div class="dataflow__icon">📊</div>
                        <h4 data-en="Analytics & alerts" data-es="Analítica y alertas">Analytics & alerts</h4>
                        <p data-en="Dashboards surface KPIs, anomaly detection, and cohort comparisons per provider." data-es="Los tableros muestran KPIs, detección de anomalías y comparativos por proveedor.">Dashboards surface KPIs, anomaly detection, and cohort comparisons per provider.</p>
                    </article>
                    <article class="dataflow__stage">
                        <div class="dataflow__icon">💸</div>
                        <h4 data-en="Finance automation" data-es="Automatización financiera">Finance automation</h4>
                        <p data-en="Automated settlements, payout schedules, and audit-ready reports close the loop." data-es="Liquidaciones automatizadas, calendarios de pago y reportes auditables cierran el ciclo.">Automated settlements, payout schedules, and audit-ready reports close the loop.</p>
                    </article>
                </div>
            </div>
            <p class="edge-text" data-en="From supplier onboarding to payouts, Rosa Mexicano POS keeps every control connected so your team can scale confidently." data-es="Desde el alta del proveedor hasta los pagos, Rosa Mexicano POS mantiene cada control conectado para escalar con confianza.">
                Proveedores alimentan catálogos y precios; el POS aplica promociones y extras, consolida ventas,
                y envía resultados a Analytics y Finanzas para reportes y liquidaciones.
            </p>
        </section>
    </main>
    <footer data-en="© {{ date('Y') }} Rosa Mexicano. All rights reserved." data-es="© {{ date('Y') }} Rosa Mexicano. Todos los derechos reservados.">
        © {{ date('Y') }} Rosa Mexicano. All rights reserved.
    </footer>
    <script>
        const langButtons = document.querySelectorAll('[data-lang-btn]');
        const translatable = document.querySelectorAll('[data-en][data-es]');
        const updateLanguage = (lang) => {
            document.documentElement.lang = lang;
            translatable.forEach((el) => {
                const copy = el.dataset[lang];
                if (copy !== undefined) {
                    el.innerHTML = copy;
                }
            });
            langButtons.forEach((btn) => {
                btn.classList.toggle('lang-toggle__btn--active', btn.dataset.langBtn === lang);
            });
        };
        langButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                updateLanguage(btn.dataset.langBtn);
            });
        });
    </script>
</body>
</html>
