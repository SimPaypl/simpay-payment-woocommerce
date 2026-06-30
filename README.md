# SimPay Online Payments for WooCommerce

Oficjalny moduł SimPay dla WooCommerce, który pozwala przyjmować płatności online w sklepie opartym o WordPress i WooCommerce.

> **Ważne:** aktualnie moduł obsługuje wyłącznie walutę **PLN**.

---

## Spis treści

- [Najważniejsze informacje](#najważniejsze-informacje)
- [Funkcje modułu](#funkcje-modułu)
- [Obsługiwane metody płatności](#obsługiwane-metody-płatności)
- [Wymagania](#wymagania)
- [Instalacja](#instalacja)
- [Konfiguracja krok po kroku](#konfiguracja-krok-po-kroku)
- [BLIK Level 0 & OneClick](#blik-level-0--oneclick)
- [Konfiguracja webhooków / IPN](#konfiguracja-webhooków--ipn)
- [Zwroty](#zwroty)
- [Ograniczenia](#ograniczenia)

---

## Najważniejsze informacje

Moduł integruje WooCommerce z SimPay i umożliwia:

- przyjmowanie płatności online w sklepie,
- przekierowanie klienta do płatności SimPay,
- płatności BLIK Level 0 (kod wpisywany na stronie sklepu, bez przekierowania),
- płatności BLIK OneClick (płatność bez kodu dla powracających klientów),
- obsługę potwierdzeń płatności przez webhook / IPN,
- obsługę zwrotów z poziomu WooCommerce,
- synchronizację statusów zwrotów po stronie WooCommerce.

Moduł został przygotowany dla sklepów rozliczanych w **PLN**. Jeśli waluta sklepu jest inna niż PLN, bramka nie będzie aktywna.

---

## Funkcje modułu

- integracja z WooCommerce jako natywna metoda płatności,
- centralna konfiguracja połączenia z SimPay w ustawieniach WooCommerce,
- osobne bramki dla wybranych kanałów płatności,
- **BLIK Level 0** – klient wpisuje kod BLIK bezpośrednio na stronie sklepu,
- **BLIK OneClick** – powracający klienci płacą bez kodu (potwierdzenie w aplikacji bankowej),
- zapis identyfikatora transakcji SimPay w zamówieniu WooCommerce,
- obsługa webhooków / IPN dla płatności, zwrotów i statusów aliasów BLIK,
- możliwość inicjowania zwrotów z poziomu panelu WooCommerce,
- zgodność z klasycznym checkoutem WooCommerce,
- przygotowana integracja z WooCommerce Blocks,
- informacja w panelu administracyjnym o dostępności nowszej wersji modułu.

---

## Obsługiwane metody płatności

Aktualnie moduł udostępnia następujące metody:

- **SimPay** – ogólna metoda płatności online,
- **BLIK** – z obsługą Level 0 (kod na stronie) i OneClick (płatność bez kodu),
- **BLIK Pay Later**,
- **PayPo**.

Dostępność konkretnych kanałów zależy od konfiguracji i aktywacji usług po stronie SimPay.

---

## Wymagania

Minimalne wymagania techniczne:

- **PHP 8.1** lub nowszy,
- aktywny **WordPress** (6.0+),
- aktywny **WooCommerce** (7.0+),
- aktywna usługa płatności w SimPay,
- poprawnie skonfigurowany webhook / IPN,
- waluta sklepu ustawiona na **PLN**.

### Wymagania dla BLIK Level 0 & OneClick

- aktywowany kanał **BLIK Level 0** w panelu SimPay,
- (dla OneClick) zatwierdzony moduł OneClick przez SimPay,
- spełnione wymagania [Checklisty BLIK](https://docs.simpay.pl),
- tryb testowy włączony w panelu SimPay (do testów z kodami testowymi).

---

## Instalacja

### Instalacja w panelu WordPress

1. Pobierz paczkę ZIP modułu.
2. Przejdź do **WordPress → Wtyczki → Dodaj wtyczkę → Wyślij wtyczkę na serwer**.
3. Wgraj plik ZIP z modułem.
4. Aktywuj wtyczkę.
5. Upewnij się, że WooCommerce jest zainstalowany i aktywny.

### Instalacja ręczna

1. Rozpakuj moduł do katalogu:

```text
wp-content/plugins/simpay-wordpress
```

2. Aktywuj wtyczkę w panelu WordPress.

---

## Konfiguracja krok po kroku

Po aktywacji modułu przejdź do:

```text
WooCommerce → Ustawienia SimPay
```

W tym miejscu skonfigurujesz dane globalne wykorzystywane przez wszystkie metody płatności.

### Wymagane pola konfiguracji

#### 1. ID usługi
Identyfikator usługi SimPay.

Ścieżka w panelu SimPay:

```text
Płatności online → Usługi → Szczegóły → ID usługi
```

#### 2. Hasło API
Hasło API / Bearer Token do komunikacji z API SimPay.

Ścieżka w panelu SimPay:

```text
Panel klienta → API → Szczegóły → Hasło / Token Bearer
```

#### 3. Klucz podpisu IPN
Klucz podpisu używany do weryfikacji notyfikacji przychodzących z SimPay.

Ścieżka w panelu SimPay:

```text
Płatności online → Usługi → Szczegóły → Ustawienia → Klucz podpisu IPN
```

#### 4. Weryfikuj adres IP w przychodzących powiadomieniach IPN
Opcjonalna weryfikacja adresu IP nadawcy webhooków / IPN.

> Jeśli sklep działa za Cloudflare lub innym reverse proxy, włączenie tej opcji może powodować odrzucanie notyfikacji. W takim przypadku zalecamy pozostawić tę opcję wyłączoną.

### Włączenie metod płatności

Po zapisaniu ustawień globalnych przejdź do:

```text
WooCommerce → Ustawienia → Płatności
```

Następnie:

1. aktywuj wybrane metody SimPay,
2. ustaw ich nazwy i opisy widoczne dla klienta,
3. zapisz zmiany.

---

## BLIK Level 0 & OneClick

### Czym jest BLIK Level 0?

BLIK Level 0 pozwala klientowi wpisać 6-cyfrowy kod BLIK bezpośrednio na stronie Twojego sklepu – bez przekierowania na zewnętrzną stronę płatności. Daje to najszybszy i najwygodniejszy proces zakupowy.

### Czym jest BLIK OneClick?

OneClick to rozszerzenie Level 0. Po pierwszej płatności kodem BLIK klient może zapisać Twój sklep jako zaufany. Każda kolejna płatność wymaga już tylko jednego kliknięcia i potwierdzenia w aplikacji bankowej – bez wpisywania kodu.

### Aktywacja w panelu SimPay

1. Przejdź do **Szczegóły usługi → Kanały płatności** w panelu SimPay.
2. Włącz kanał **BLIK Level 0**.
3. (Opcjonalnie) Wyślij zgłoszenie o rejestrację modułu **OneClick**.

### Konfiguracja w WooCommerce

Przejdź do:

```text
WooCommerce → Ustawienia → Płatności → SimPay – BLIK
```

Włącz opcje:

- **BLIK Level 0** – wpisywanie kodu na stronie zamówienia,
- **BLIK OneClick** – płatność bez kodu dla powracających klientów,
- **Etykieta aliasu BLIK** – nazwa wyświetlana klientowi w aplikacji bankowej (musi być zgodna z certyfikacją BLIK).

### Jak to działa technicznie

#### Pierwsza płatność (rejestracja aliasu)

1. Klient wpisuje kod BLIK na stronie checkout.
2. Moduł tworzy transakcję z `directChannel: blik-level0`.
3. Kod BLIK jest wysyłany do API SimPay wraz z danymi aliasu (`value` + `type`).
4. Klient potwierdza płatność w aplikacji bankowej.
5. Aplikacja bankowa pyta klienta czy zapisać sklep jako zaufany.
6. SimPay wysyła IPN `blik:alias_status_changed` ze statusem i UUID aliasu.

#### Kolejne płatności (OneClick)

1. Zalogowany klient z aktywnym aliasem widzi opcję "Zapłać bez kodu".
2. Moduł wysyła żądanie z aliasem (`uuid`) – bez kodu BLIK.
3. Klient otrzymuje powiadomienie push w aplikacji bankowej.
4. Po potwierdzeniu – płatność zrealizowana.

## Konfiguracja webhooków / IPN

Moduł korzysta z endpointu WooCommerce API dla notyfikacji SimPay.

Adres webhooka należy ustawić w panelu SimPay jako URL notyfikacji.

Domyślny adres webhooka wygląda następująco:

```text
/wc-api/simpay/
```

Przykład:

```text
https://twoj-sklep.pl/wc-api/simpay/
```

### Co obsługuje webhook

Webhook odpowiada m.in. za:

- potwierdzenie opłacenia zamówienia,
- aktualizację statusu zamówienia w WooCommerce,
- synchronizację statusów zwrotów,
- odbiór statusów aliasów BLIK (OneClick),
- odbiór statusów kodów BLIK Level 0,
- odbiór testowych notyfikacji IPN.

### Co sprawdzić, jeśli IPN nie działa

Najczęstsze przyczyny problemów:

- błędny `Service ID`,
- błędny `IPN signature key`,
- niepoprawny URL webhooka w panelu SimPay,
- blokowanie notyfikacji przez reverse proxy / Cloudflare,
- włączona walidacja IP przy środowisku proxy,
- brak dostępu publicznego do endpointu sklepu.

---

## Zwroty

Moduł wspiera zwroty z poziomu WooCommerce.

### Jak działa proces zwrotu

1. Administrator inicjuje zwrot w WooCommerce.
2. Moduł wysyła żądanie zwrotu do SimPay.
3. SimPay zwraca identyfikator refundu.
4. Status zwrotu jest synchronizowany z WooCommerce po webhooku.

### Zwroty częściowe

Moduł obsługuje również **zwroty częściowe**.

Jeśli w WooCommerce zostanie podana konkretna kwota zwrotu, moduł przekaże ją dalej do API SimPay jako wartość `amount`.

### Ważna uwaga

Aby zwroty działały prawidłowo, w zamówieniu musi być zapisany identyfikator transakcji SimPay. Jest on zapisywany automatycznie podczas poprawnie utworzonej płatności.

---

## Ograniczenia

Aktualny zakres modułu obejmuje następujące ograniczenia:

- obsługiwana waluta to wyłącznie **PLN**,
- BLIK Level 0 i OneClick wymagają osobnej aktywacji w panelu SimPay,
- OneClick wymaga zalogowanego klienta (konto w sklepie),
- dostępność poszczególnych kanałów zależy od konfiguracji po stronie SimPay,
- poprawne działanie płatności i zwrotów wymaga aktywnego oraz poprawnie skonfigurowanego webhooka,
- część funkcji administracyjnych i synchronizacyjnych zależy od poprawnej komunikacji z API SimPay.

---

## SDK

Moduł korzysta z pakietu `simpay/ecommerce` (dołączony w katalogu `vendor/`) który zapewnia:

- klienta HTTP do komunikacji z API SimPay,
- budowanie payloadów transakcji,
- walidację IPN (podpis, IP),
- obsługę BLIK Level 0 i OneClick (aliasy).

---

### Aktualizacje modułu

Moduł sprawdza dostępność nowszej wersji przez API SimPay i może wyświetlić odpowiednie powiadomienie w panelu administracyjnym.

---