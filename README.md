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
- [Konfiguracja webhooków / IPN](#konfiguracja-webhooków--ipn)
- [Zwroty](#zwroty)
- [Ograniczenia](#ograniczenia)

---

## Najważniejsze informacje

Moduł integruje WooCommerce z SimPay i umożliwia:

- przyjmowanie płatności online w sklepie,
- przekierowanie klienta do płatności SimPay,
- obsługę potwierdzeń płatności przez webhook / IPN,
- obsługę zwrotów z poziomu WooCommerce,
- synchronizację statusów zwrotów po stronie WooCommerce.

Moduł został przygotowany dla sklepów rozliczanych w **PLN**. Jeśli waluta sklepu jest inna niż PLN, bramka nie będzie aktywna.

---

## Funkcje modułu

- integracja z WooCommerce jako natywna metoda płatności,
- centralna konfiguracja połączenia z SimPay w ustawieniach WooCommerce,
- osobne bramki dla wybranych kanałów płatności,
- zapis identyfikatora transakcji SimPay w zamówieniu WooCommerce,
- obsługa webhooków / IPN dla płatności i zwrotów,
- możliwość inicjowania zwrotów z poziomu panelu WooCommerce,
- zgodność z klasycznym checkoutem WooCommerce,
- przygotowana integracja z WooCommerce Blocks,
- informacja w panelu administracyjnym o dostępności nowszej wersji modułu.

---

## Obsługiwane metody płatności

Aktualnie moduł udostępnia następujące metody:

- **SimPay** – ogólna metoda płatności online,
- **BLIK**,
- **BLIK Pay Later**,
- **PayPo**.

Dostępność konkretnych kanałów zależy od konfiguracji i aktywacji usług po stronie SimPay.

---

## Wymagania

Minimalne wymagania techniczne:

- **PHP 8.0** lub nowszy,
- aktywny **WordPress**,
- aktywny **WooCommerce**,
- aktywna usługa płatności w SimPay,
- poprawnie skonfigurowany webhook / IPN,
- waluta sklepu ustawiona na **PLN**.

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
- dostępność poszczególnych kanałów zależy od konfiguracji po stronie SimPay,
- poprawne działanie płatności i zwrotów wymaga aktywnego oraz poprawnie skonfigurowanego webhooka,
- część funkcji administracyjnych i synchronizacyjnych zależy od poprawnej komunikacji z API SimPay.

---

### Aktualizacje modułu

Moduł sprawdza dostępność nowszej wersji przez API SimPay i może wyświetlić odpowiednie powiadomienie w panelu administracyjnym.

---