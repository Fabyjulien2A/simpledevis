<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarifs - SimpleDevis</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            color: #1f2937;
        }

        .header {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 18px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 30px;
            font-weight: 700;
            text-decoration: none;
            color: #111827;
        }

        .nav {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .nav a {
            text-decoration: none;
            color: #4b5563;
            font-size: 14px;
        }

        .nav a:hover {
            color: #111827;
        }

        .btn-primary {
            background: #2563eb;
            color: white !important;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 60px 24px 90px;
        }

        .intro {
            text-align: center;
            margin-bottom: 50px;
        }

        .intro h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
        }

        .intro p {
            margin-top: 12px;
            font-size: 16px;
            color: #6b7280;
        }

        .pricing-grid {
            display: flex;
            justify-content: center;
            gap: 40px;
            align-items: stretch;
            flex-wrap: wrap;
        }

        .pricing-card {
            position: relative;
            width: 320px;
            min-height: 460px;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .pricing-card.featured {
            border: 2px solid #2563eb;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.10);
        }

        .badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: #2563eb;
            color: white;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 14px;
            border-radius: 999px;
        }

        .card-title {
            font-size: 30px;
            font-weight: 700;
            margin: 0;
            text-align: center;
        }

        .card-subtitle {
            text-align: center;
            font-size: 14px;
            color: #6b7280;
            margin-top: 10px;
            min-height: 38px;
        }

        .price {
            text-align: center;
            margin-top: 24px;
        }

        .price strong {
            font-size: 44px;
            color: #111827;
        }

        .price span {
            font-size: 18px;
            color: #6b7280;
        }

        .features {
            margin-top: 28px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            font-size: 15px;
            color: #374151;
        }

        .features div {
            text-align: center;
        }

        .card-button {
            margin-top: 34px;
            display: inline-block;
            text-align: center;
            text-decoration: none;
            padding: 14px 18px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 15px;
        }

        .card-button-outline {
            border: 1px solid #d1d5db;
            color: #111827;
            background: #ffffff;
        }

        .card-button-outline:hover {
            background: #f9fafb;
        }

        .card-button-filled {
            background: #2563eb;
            color: #ffffff;
        }

        .card-button-filled:hover {
            background: #1d4ed8;
        }

        .faq-section {
            margin-top: 100px;
        }

        .faq-title {
            text-align: center;
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 36px;
        }

        .faq-list {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .faq-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
        }

        .faq-item h3 {
            margin: 0;
            font-size: 20px;
        }

        .faq-item p {
            margin: 10px 0 0;
            color: #6b7280;
            line-height: 1.6;
        }

        @media (max-width: 1100px) {
            .pricing-grid {
                gap: 24px;
            }

            .pricing-card {
                width: 300px;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 14px;
            }

            .nav {
                flex-wrap: wrap;
                justify-content: center;
            }

            .intro h1 {
                font-size: 28px;
            }

            .pricing-grid {
                flex-direction: column;
                align-items: center;
            }

            .pricing-card {
                width: 100%;
                max-width: 360px;
                min-height: auto;
            }

            .faq-title {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>

    <header class="header">
        <div class="header-container">
            <a href="{{ url('/') }}" class="logo">SimpleDevis</a>

            <nav class="nav">
                <a href="{{ url('/') }}">Accueil</a>
                <a href="{{ route('login') }}">Connexion</a>
                <a href="{{ route('register') }}" class="btn-primary">S’inscrire</a>
            </nav>
        </div>
    </header>

    <main class="main">
        <section class="intro">
            <h1>Nos offres</h1>
            <p>Choisis l’offre qui correspond à ton activité.</p>
        </section>

        <section class="pricing-grid">

    {{-- Découverte --}}
    <div class="pricing-card">
        <div>
            <h2 class="card-title">Découverte</h2>
            <p class="card-subtitle">Pour tester l’outil tranquillement</p>

            <div class="price">
                <strong>0€</strong><span>/mois</span>
            </div>

            <div class="features">
                <div>Jusqu’à 5 clients</div>
                <div>Jusqu’à 10 devis</div>
                <div>Jusqu’à 10 factures</div>
                <div>PDF inclus</div>
                <div>Support basique</div>
            </div>
        </div>

        <a href="{{ route('register') }}" class="card-button card-button-outline">
            Commencer
        </a>
    </div>

    {{-- Pro --}}
    <div class="pricing-card featured">
        <div class="badge">Recommandé</div>

        <div>
            <h2 class="card-title">Pro</h2>
            <p class="card-subtitle">Pour gérer ton activité sérieusement</p>

            <div class="price">
                <strong>12€</strong><span>/mois</span>
            </div>

            <div class="features">
                <div>Clients illimités</div>
                <div>Devis illimités</div>
                <div>Factures illimitées</div>
                <div>Suivi des paiements</div>
                <div>Dashboard complet</div>
                <div>PDF professionnels</div>
                <div>Support prioritaire</div>
            </div>
        </div>

        @auth
            <form action="{{ route('billing.subscribe', 'pro') }}" method="POST">
                @csrf
                <button type="submit" class="card-button card-button-filled" style="width:100%; border:none; cursor:pointer;">
                    Choisir Pro
                </button>
            </form>
        @else
            <a href="{{ route('register') }}" class="card-button card-button-filled">
                Choisir Pro
            </a>
        @endauth
    </div>

    {{-- Business --}}
    <div class="pricing-card">
        <div>
            <h2 class="card-title">Business</h2>
            <p class="card-subtitle">Pour aller plus loin avec ton activité</p>

            <div class="price">
                <strong>29€</strong><span>/mois</span>
            </div>

            <div class="features">
                <div>Tout dans l’offre Pro</div>
                <div>Options avancées futures</div>
                <div>Priorité sur les nouveautés</div>
                <div>Assistance renforcée</div>
                <div>Pensé pour une activité en croissance</div>
            </div>
        </div>

        @auth
            <form action="{{ route('billing.subscribe', 'business') }}" method="POST">
                @csrf
                <button type="submit" class="card-button card-button-outline" style="width:100%; cursor:pointer;">
                    Choisir Business
                </button>
            </form>
        @else
            <a href="{{ route('register') }}" class="card-button card-button-outline">
                Choisir Business
            </a>
        @endauth
    </div>

</section>

        <section class="faq-section">
            <h2 class="faq-title">Questions fréquentes</h2>

            <div class="faq-list">
                <div class="faq-item">
                    <h3>Puis-je changer d’offre plus tard ?</h3>
                    <p>Oui, tu pourras passer à une offre supérieure à tout moment.</p>
                </div>

                <div class="faq-item">
                    <h3>Y a-t-il un engagement ?</h3>
                    <p>Non, les offres sont pensées pour rester simples et flexibles.</p>
                </div>

                <div class="faq-item">
                    <h3>La formule gratuite suffit-elle pour tester ?</h3>
                    <p>Oui, elle est là pour découvrir l’outil avant de passer à une formule plus complète.</p>
                </div>
            </div>
        </section>
    </main>

</body>
</html>