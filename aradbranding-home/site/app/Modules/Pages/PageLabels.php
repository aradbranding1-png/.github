<?php

declare(strict_types=1);

namespace App\Modules\Pages;

/** Interface words on a public page follow the page's own language (fallback: English). */
final class PageLabels
{
    private const LABELS = [
        'fa' => ['contact_note' => 'ارتباط با این کسب‌وکار فقط از داخل سامانه توسعه تجارت انجام می‌شود.', 'unlock_title' => 'مشاهده اطلاعات کامل', 'unlock_text' => 'محصولات، خدمات و بازارهای هدف این کسب‌وکار', 'unlock_btn' => 'مشاهده با :n Star', 'balance' => 'موجودی شما: :n Star', 'buy' => 'خرید Stars', 'domestic' => 'داخلی', 'international' => 'بین‌المللی', 'about' => 'درباره ما', 'products' => 'محصولات', 'services' => 'خدمات', 'markets' => 'بازارهای هدف',
            'contact' => 'ارتباط با این کسب‌وکار', 'languages' => 'زبان‌های دیگر این صفحه', 'verified' => 'تأییدشده',
            'locked_title' => 'اطلاعات کامل این کسب‌وکار', 'locked_text' => 'محصولات، خدمات و بازارهای هدف پس از ورود نمایش داده می‌شود.',
            'login' => 'ورود', 'register' => 'عضویت رایگان', 'edit' => 'ویرایش این صفحه', 'network' => 'در سامانه توسعه تجارت'],
        'en' => ['contact_note' => 'You can reach this business only inside the Trade Development Network.', 'unlock_title' => 'View full profile', 'unlock_text' => 'Products, services and target markets of this business', 'unlock_btn' => 'View for :n Stars', 'balance' => 'Your balance: :n Stars', 'buy' => 'Buy Stars', 'domestic' => 'domestic', 'international' => 'international', 'about' => 'About us', 'products' => 'Products', 'services' => 'Services', 'markets' => 'Target markets',
            'contact' => 'Contact', 'languages' => 'This page in other languages', 'verified' => 'Verified',
            'locked_title' => 'Full business profile', 'locked_text' => 'Products, services and target markets are shown after you sign in.',
            'login' => 'Sign in', 'register' => 'Join free', 'edit' => 'Edit this page', 'network' => 'on Trade Development Network'],
        'ar' => ['contact_note' => 'التواصل مع هذا النشاط يتم داخل شبكة تنمية التجارة فقط.', 'unlock_title' => 'عرض الملف الكامل', 'unlock_text' => 'منتجات وخدمات وأسواق هذا النشاط', 'unlock_btn' => 'عرض مقابل :n نجمة', 'balance' => 'رصيدك: :n نجمة', 'buy' => 'شراء النجوم', 'domestic' => 'محلي', 'international' => 'دولي', 'about' => 'من نحن', 'products' => 'المنتجات', 'services' => 'الخدمات', 'markets' => 'الأسواق المستهدفة',
            'contact' => 'التواصل', 'languages' => 'هذه الصفحة بلغات أخرى', 'verified' => 'موثّق',
            'locked_title' => 'الملف التجاري الكامل', 'locked_text' => 'تظهر المنتجات والخدمات والأسواق بعد تسجيل الدخول.',
            'login' => 'تسجيل الدخول', 'register' => 'اشترك مجاناً', 'edit' => 'تعديل الصفحة', 'network' => 'على شبكة تنمية التجارة'],
        'tr' => ['contact_note' => 'Bu işletmeyle yalnızca Ticaret Geliştirme Ağı içinden iletişim kurulur.', 'unlock_title' => 'Tam profili görüntüle', 'unlock_text' => 'Bu işletmenin ürünleri, hizmetleri ve hedef pazarları', 'unlock_btn' => ':n Yıldız ile görüntüle', 'balance' => 'Bakiyeniz: :n Yıldız', 'buy' => 'Yıldız satın al', 'domestic' => 'yurt içi', 'international' => 'uluslararası', 'about' => 'Hakkımızda', 'products' => 'Ürünler', 'services' => 'Hizmetler', 'markets' => 'Hedef pazarlar',
            'contact' => 'İletişim', 'languages' => 'Bu sayfa diğer dillerde', 'verified' => 'Doğrulanmış',
            'locked_title' => 'Tam işletme profili', 'locked_text' => 'Ürünler, hizmetler ve pazarlar giriş yaptıktan sonra gösterilir.',
            'login' => 'Giriş yap', 'register' => 'Ücretsiz katıl', 'edit' => 'Sayfayı düzenle', 'network' => 'Ticaret Geliştirme Ağında'],
        'fr' => ['contact_note' => 'Vous pouvez contacter cette entreprise uniquement via le Réseau de développement du commerce.', 'unlock_title' => 'Voir le profil complet', 'unlock_text' => 'Produits, services et marchés cibles de cette entreprise', 'unlock_btn' => 'Voir pour :n Stars', 'balance' => 'Votre solde : :n Stars', 'buy' => 'Acheter des Stars', 'domestic' => 'national', 'international' => 'international', 'about' => 'À propos', 'products' => 'Produits', 'services' => 'Services', 'markets' => 'Marchés cibles',
            'contact' => 'Contact', 'languages' => 'Cette page dans d’autres langues', 'verified' => 'Vérifiée',
            'locked_title' => 'Profil complet de l’entreprise', 'locked_text' => 'Les produits, services et marchés cibles s’affichent après connexion.',
            'login' => 'Se connecter', 'register' => 'Inscription gratuite', 'edit' => 'Modifier cette page', 'network' => 'sur le Réseau de développement du commerce'],
        'ru' => ['contact_note' => 'Связаться с компанией можно только внутри сети развития торговли.', 'unlock_title' => 'Открыть полный профиль', 'unlock_text' => 'Продукция, услуги и рынки компании', 'unlock_btn' => 'Открыть за :n звёзд', 'balance' => 'Ваш баланс: :n звёзд', 'buy' => 'Купить звёзды', 'domestic' => 'внутренний', 'international' => 'международный', 'about' => 'О нас', 'products' => 'Продукция', 'services' => 'Услуги', 'markets' => 'Целевые рынки',
            'contact' => 'Контакты', 'languages' => 'Эта страница на других языках', 'verified' => 'Проверено',
            'locked_title' => 'Полный профиль компании', 'locked_text' => 'Продукция, услуги и рынки доступны после входа.',
            'login' => 'Войти', 'register' => 'Регистрация', 'edit' => 'Редактировать', 'network' => 'в сети развития торговли'],
        'de' => ['contact_note' => 'Dieses Unternehmen erreichen Sie nur innerhalb des Handelsnetzwerks.', 'unlock_title' => 'Vollständiges Profil ansehen', 'unlock_text' => 'Produkte, Leistungen und Zielmärkte dieses Unternehmens', 'unlock_btn' => 'Für :n Sterne ansehen', 'balance' => 'Ihr Guthaben: :n Sterne', 'buy' => 'Sterne kaufen', 'domestic' => 'Inland', 'international' => 'international', 'about' => 'Über uns', 'products' => 'Produkte', 'services' => 'Leistungen', 'markets' => 'Zielmärkte',
            'contact' => 'Kontakt', 'languages' => 'Diese Seite in anderen Sprachen', 'verified' => 'Verifiziert',
            'locked_title' => 'Vollständiges Unternehmensprofil', 'locked_text' => 'Produkte, Leistungen und Märkte sehen Sie nach der Anmeldung.',
            'login' => 'Anmelden', 'register' => 'Kostenlos beitreten', 'edit' => 'Seite bearbeiten', 'network' => 'im Handelsnetzwerk'],
    ];

    /** @return array<string, string> */
    public static function for(string $lang): array
    {
        return self::LABELS[$lang] ?? self::LABELS['en'];
    }
}
