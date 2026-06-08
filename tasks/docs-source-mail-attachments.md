# Task: Doklady — přílohy navázaných došlých zpráv v detailu

## Kontext

Ve starém Shipardu se v detailu dokladu (přijatá faktura) zobrazují PDF přílohy
zprávy došlé pošty, ze které doklad vznikl (vazba `e10docs-inbox`). V novém Shipardu
tato vazba existuje datově, ale **detail dokladu přílohy navázaných zpráv
nezobrazuje**.

Vazba žije na zprávě: `core_mail_incoming_messages.target_table_id` +
`target_row` ukazují na business entitu, která ze zprávy vznikla; index `idx_target`
slouží přesně k **zpětnému dohledání zpráv, ze kterých entita vznikla**. Přílohy
fyzicky patří zprávě (`core_attachments_files` s `table_id = 303`, `record_id =
message.id`).

**Tato funkce není import-specifická.** Stejnou vazbu (`message.target`) naplňuje i
nativní AI flow (zpráva → extrahovaný dokument → uživatel potvrdí → vznikne doklad,
`docState=40`). Takže zobrazení příloh zdrojových zpráv u dokladu slouží **oběma**
cestám — importu i AI pipeline. Importní task `old_shipard:…/07b-mail.md` jen tuto
vazbu naplní; samotné zobrazení patří sem do `nov_shipard`.

Cíl: v **detailu dokladu** (`DocsHeadsViewer::renderDetail`) přibude tab/sekce
„Přílohy", která agreguje přílohy ze všech zpráv mířících na tento doklad, s odkazem
na zdrojovou zprávu.

## Před implementací přečti

- **`modules/docs/core/src/DocsHeadsViewer.php`** — `renderDetail(int $recordId)`.
  Aktuálně vrací jen tab `overview` (`type: 'properties'`). Sem přidat tab/sekci
  s přílohami.
- **`modules/core/mail/src/IncomingMessagesViewer.php`** — **klíčový vzor**. Jeho
  detail panel má tab „Přílohy" (4 taby: Obsah / Přílohy / Analýzy / Originál).
  Zjisti, jaký `content type` pro grid náhledů příloh viewer detail framework
  podporuje, a **použij stejný**. Nezaváděj nový typ, pokud existuje.
- **`modules/core/attachments/src/AttachmentService.php`** —
  `listAttachments(int $tableId, int $recordId, bool $includeDeleted = false)`
  vrací přílohy záznamu. Použij pro načtení příloh každé zdrojové zprávy.
- **`docs/attachments.md`** — formát položky přílohy + `thumbnail_url`
  (`/_attachments/{id}/thumbnail?w=300`).
- **`modules/core/mail/tables/core_mail_incoming_messages.jsonc`** — `idx_target`
  (`target_table_id`, `target_row`), `tableId = 303`.

## Co implementovat

### 1. Dohledání zdrojových zpráv dokladu

Helper (v `DocsHeadsViewer`, nebo malá sdílená třída, ať jde použít i jinde —
např. budoucí detail vydané faktury):

```php
/**
 * Zprávy došlé pošty, ze kterých tento doklad vznikl (vazba přes
 * message.target_table_id + target_row; index idx_target). Trash (90) vynechán.
 *
 * @return list<array{id:int, message_id:string, received_at:?string}>
 */
private function sourceMessages(int $docId): array
{
    return $this->db->fetchAll(
        'SELECT `id`, `message_id`, `received_at`'
        . ' FROM `core_mail_incoming_messages`'
        . ' WHERE `target_table_id` = %s AND `target_row` = %i AND `docState` != %i'
        . ' ORDER BY `received_at` ASC, `id` ASC',
        'docs_core_heads', $docId, 90,
    );
}
```

> **Pozn.:** `target_table_id` je **název** tabulky (`docs_core_heads`), shodně s tím,
> co zapisuje importer (`07b-mail.md`) i nativní AI flow. Pokud by se v kódbázi
> ukázalo, že jiná místa píší jinou konvenci (dokumentace zmiňuje zastaralý příklad
> `economy_docs_issued_invoices_received`), sjednoť na `docs_core_heads`.

### 2. Agregace příloh

Pro každou zdrojovou zprávu načti přílohy přes
`AttachmentService::listAttachments(303, $message['id'])`. Sestav strukturu
seskupenou per zpráva (ať je v UI vidět, ze které zprávy příloha pochází):

```
[
  { message_id: "MSG-20250602-0007", received_at: "...", attachments: [ {id, name, mime_type, thumbnail_url, ...}, ... ] },
  ...
]
```

`raw_source_attachment` (.eml originál) sem **nepatří** — `listAttachments` vrací
obsahové přílohy; .eml je drženo zvlášť na zprávě a u importu stejně nevzniká.

### 3. Tab/sekce v `renderDetail`

Do návratových `tabs` přidej tab „Přílohy" **jen když existují zdrojové zprávy s
přílohami** (jinak ho nezobrazuj). Obsah vyrenderuj stejným `content type`, jaký
používá `IncomingMessagesViewer` pro svůj tab Přílohy (grid náhledů). Pokud ten typ
neumí víc skupin, vyrenderuj ploše všechny přílohy a u každé doplň label se zdrojovou
zprávou (`#MSG-…`); ideálně ať je `message_id` klikatelné na detail zprávy (pokud to
viewer detail framework umožňuje, jinak text).

Počet příloh případně zobraz i v labelu tabu (např. „Přílohy (2)").

### 4. (Volitelné, nižší priorita) Editor dokladu

Stejnou agregaci lze ukázat i v editačním formuláři dokladu. Pro MVP **stačí detail
prohlížeče** (read-only zobrazení). Editor zmiň jen jako follow-up.

## Hotovo když

1. Detail dokladu (`DocsHeadsViewer::renderDetail`) zobrazuje přílohy všech zpráv,
   jejichž `target_table_id='docs_core_heads'` a `target_row` = id dokladu.
2. Přílohy jsou seskupené (nebo aspoň označené) podle zdrojové zprávy (`#MSG-…`).
3. Náhledy se zobrazují přes existující `thumbnail_url` (PDF/obrázky); klik
   stáhne/otevře přílohu.
4. Tab „Přílohy" se nezobrazí, pokud doklad nemá žádnou navázanou zprávu s přílohou.
5. Funguje pro doklad navázaný importem (`07b-mail.md`) i pro doklad z nativního AI
   flow (oba plní `message.target`).
6. `.eml` originál se v tomto tabu neobjevuje.

## Doporučené pořadí implementace

1. `sourceMessages()` helper + smoke (ručně nastavit `target` na jedné zprávě,
   ověřit dotaz).
2. Agregace příloh přes `AttachmentService`.
3. Render tab „Přílohy" v `renderDetail` (mirror `IncomingMessagesViewer`).
4. Ověřit na reálném navázaném dokladu (po běhu importu) + na AI-flow dokladu.

## Otevřené body / rozhodnutí

1. **Více zpráv na doklad.** Doklad může mít víc zdrojových zpráv (1 doklad : N
   zpráv). Seskupení per zpráva to řeší přirozeně.
2. **Content type pro grid v detailu.** Závisí na tom, co viewer detail framework
   umí (zjisti z `IncomingMessagesViewer`). Pokud neumí seskupení, ploché zobrazení
   s labelem zdrojové zprávy je dostatečné.
3. **Reverzní směr (na zprávě odkaz na doklad).** Detail zprávy už má pole
   `target_table_id`/`target_row`; případné zobrazení odkazu „vznikl doklad #…" v
   detailu zprávy je samostatný drobný follow-up, není součástí tohoto tasku.
