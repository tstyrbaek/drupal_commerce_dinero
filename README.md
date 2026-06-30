# Commerce Dinero

Drupal Commerce-modul der opretter fakturakladder i [Dinero](https://dinero.dk) ud fra webshop-ordrer.

Modulet finder eller opretter en kontakt i Dinero, mapper ordrelinjer og fragt til fakturalinjer, og gemmer en reference til den oprettede faktura på ordren. Fakturaen bogføres **ikke** automatisk i Dinero — den oprettes som kladde, så den kan gennemgås og godkendes manuelt.

## Krav

- Drupal 10 eller 11
- [Drupal Commerce](https://www.drupal.org/project/commerce) (`commerce_order`)
- [Key](https://www.drupal.org/project/key) — til sikker opbevaring af API-hemmeligheder
- Views (bruges til ordreoversigten)
- En Dinero-konto med personlig integration og organisations-API-nøgle

## Installation

1. Placér modulet i `web/modules/custom/commerce_dinero`.
2. Aktivér modulet: `drush en commerce_dinero -y`
3. Giv relevante roller tilladelsen **Create Dinero invoice from order**.

Ved installation tilføjes et **Dinero**-felt til ordreoversigten (`commerce_orders` view).

## Opsætning i Dinero

Du skal have følgende fra Dinero:

| Oplysning | Hvor findes den |
|-----------|-----------------|
| **Organization ID** (FirmaId) | Nederst til venstre i Dinero, når du er logget ind |
| **Client ID** | Fra din personlige integration på [developer.dinero.dk](https://developer.dinero.dk) |
| **Client secret** | Samme sted som Client ID |
| **API key** | Organisations-API-nøgle genereret i Dinero |

Opret to nøgler i Key-modulet (én til client secret, én til API key), og vælg dem i modulets indstillinger.

## Konfiguration

Gå til **Commerce → Configuration → Dinero** (`/admin/commerce/config/dinero`).

### API credentials

- **Organization ID**, **Client ID**, **Client secret** og **API key**
- Brug knappen **Test connection** for at verificere forbindelsen

### Invoice settings

- **Default account number** — Dinero-kontonummer på fakturalinjer (standard: 1000)
- **Default line unit** — enhedstype på linjer (f.eks. `parts`, `hours`, `shipment`)

## Brug

### Opret faktura fra en ordre

1. Åbn en ordre i admin (ikke en kurv, og ikke i tilstanden *draft* eller *canceled*).
2. Klik **Opret faktura i Dinero** under ordrens handlinger.
3. Gennemgå linjerne på bekræftelsessiden, og bekræft oprettelsen.

Modulet:

- Finder en eksisterende Dinero-kontakt via kundens e-mail, eller opretter en ny ud fra faktureringsadressen
- Opretter en fakturakladde i Dinero
- Gemmer `invoice_guid`, `contact_guid` og `invoiced_at` på ordren

En ordre kan kun faktureres én gang i Dinero.

### Fakturastatus og PDF

På ordresiden vises en sektion **Sendt til Dinero** med:

- Link til PDF-download (kun når fakturaen er **bogført** i Dinero)
- Dato for hvornår fakturaen blev sendt til Dinero

Cron-jobbet synkroniserer løbende fakturastatus fra Dinero, så PDF-linket bliver tilgængeligt, når fakturaen er bogført.

I ordreoversigten vises kolonnen **Dinero** med status *Kladde* eller *Faktureret*.

## Hvad sendes til Dinero?

| Felt | Kilde |
|------|-------|
| Kontakt | Faktureringsprofil + ordre-e-mail |
| Dato | Dagens dato |
| Valuta | Ordrens totalpris |
| Ekstern reference | `Drupal order {ordrenummer}` |
| Kommentar | Butiks-URL og ordrenummer |
| Produktlinjer | Ordrelinjer (ekskl. moms) + fragt |
| Betalingsbetingelser | Se nedenfor |

Beløb sendes ekskl. moms. Moms håndteres af Dinero ud fra kontokonfiguration.

## Betalingsbetingelser

| Ordrestatus | Adfærd |
|-------------|--------|
| **Betalt** (`$order->isPaid()`) | Fakturaen får `PaymentConditionType: Paid` — svarer til *Fakturaen er betalt* i Dinero |
| **Ikke betalt** | Ingen betalingsbetingelser sendes; Dinero bruger kontaktens standard eller organisationens fakturaindstillinger |

Gyldige værdier i Dinero API er: `Netto`, `NettoCash`, `CurrentMonthOut` og `Paid`.

Modulet registrerer **ikke** betalinger i Dinero's regnskab efter bogføring — det kræver et separat kald til betalings-endpointet og er ikke en del af dette modul.

## Tilladelser

| Tilladelse | Beskrivelse |
|------------|-------------|
| `administer commerce dinero` | Konfigurér API og fakturaindstillinger |
| `create commerce dinero invoice` | Opret faktura fra ordre og download PDF |

## Udvidelse via events

Før en faktura oprettes, dispatches eventet `commerce_dinero.invoice_pre_create` (`InvoicePreCreateEvent`). Et custom modul kan ændre payload'en:

```php
use Drupal\commerce_dinero\Event\InvoicePreCreateEvent;

/**
 * Implements hook_event_dispatcher_commerce_dinero_invoice_pre_create().
 *
 * Or subscribe via services.yml to InvoicePreCreateEvent::EVENT_NAME.
 */
function mymodule_commerce_dinero_invoice_pre_create(InvoicePreCreateEvent $event): void {
  $payload = $event->getPayload();
  $payload['Description'] = 'Min tilpassede overskrift';
  $event->setPayload($payload);
}
```

## Data på ordren

Modulet gemmer følgende i ordrens `data`-felt:

| Nøgle | Indhold |
|-------|---------|
| `commerce_dinero.invoice_guid` | Dinero faktura-GUID |
| `commerce_dinero.contact_guid` | Dinero kontakt-GUID |
| `commerce_dinero.invoiced_at` | Unix-tidsstempel for oprettelse |
| `commerce_dinero.invoice_status` | Dinero-status (f.eks. `Draft`, `Booked`) |

## Begrænsninger

- Fakturaer oprettes som **kladde** og bogføres ikke automatisk
- Ingen automatisk fakturaoprettelse ved ordreafslutning — det sker manuelt fra ordresiden
- Ingen synkronisering af produkter, momsindstillinger eller betalingsregistrering
- Kontakter matches kun på e-mail; eksisterende kontakter opdateres ikke
- PDF er kun tilgængelig for bogførte fakturaer

## Fejlfinding

- **Could not connect to Dinero** — tjek Organization ID, Client ID, client secret og API key. Brug *Test connection*.
- **The order does not have a billing profile** — ordren mangler faktureringsadresse
- **Validation Error (PaymentConditionType)** — kontakt modulets vedligeholder; gyldig værdi for betalt er `Paid`
- **PDF er tilgængelig, når fakturaen er bogført** — bogfør fakturaen manuelt i Dinero; status opdateres via cron eller ved genindlæsning af ordresiden

Logbeskeder skrives til kanalen `commerce_dinero`.

## Services

| Service ID | Klasse |
|------------|--------|
| `commerce_dinero.api_client` | HTTP-klient mod Dinero REST API |
| `commerce_dinero.token_manager` | OAuth-tokenhåndtering |
| `commerce_dinero.contact_manager` | Find/opret Dinero-kontakter |
| `commerce_dinero.order_invoice_mapper` | Map ordre til faktura-payload |
| `commerce_dinero.invoice_manager` | Opret og synkronisér fakturaer |
| `commerce_dinero.invoice_url_builder` | Byg PDF-URL'er |

## Licens

GPL-2.0-or-later
