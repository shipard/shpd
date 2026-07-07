<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

/**
 * Generates fake incoming e-mail messages for `core.mail` seeding.
 *
 * Identifikace: `message_id` začíná prefixem `TEST-MSG-` — podle toho
 * `seed-mail-clear` pozná a smaže. `source_type` je vždy `1` (manual).
 *
 * Distribuce `docState` (upravená §7.2 po oddělení analysis_state):
 *   60 % Nová (10), 20 % K řešení (20), 15 % Hotovo (40), 5 % Archiv (80).
 * `analysis_state`: Nová → 10 (Ve frontě), K řešení/Hotovo → 30 (Analyzováno),
 * Archiv → 0 (Bez analýzy).
 */
class FakeIncomingMessageGenerator
{
    public const ID_PREFIX = 'TEST-MSG-';

    /** Česká klíčová slova, která rozhodnou o `primary_type`. */
    private const INVOICE_KEYWORDS = ['faktura', 'platba', 'platbe', 'úhrada', 'uhrada'];

    private const SUBJECT_TEMPLATES_INVOICE = [
        'Faktura č. %06d — vyúčtování služeb',
        'Přijatá faktura 2026/%04d',
        'Výzva k platbě faktury č. %06d',
        'Faktura za služby — období %s/2026',
        'Zálohová faktura č. %06d',
        'Daňový doklad — úhrada faktury %06d',
    ];

    private const SUBJECT_TEMPLATES_OTHER = [
        'Potvrzení objednávky č. OBJ-2026-%04d',
        'Newsletter — novinky %s 2026',
        'Nabídka spolupráce — %s s.r.o.',
        'Reklamace — č. jednání %04d',
        'Dotaz ohledně ceníku pro rok 2026',
        'Upozornění — nová verze aplikace',
        'Poděkování za Vaši objednávku',
        'Odpověď na dotaz zákazníka #%04d',
        'Aktualizace obchodních podmínek',
        'Pozvánka na konferenci — %s 2026',
    ];

    private const CZECH_MONTHS = [
        'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec',
    ];

    private const SENDER_COMPANIES = [
        'Alfa Tech', 'Beta Logistik', 'Moravská Stavební', 'Pražská Energy',
        'Česká Finance', 'Global Consulting', 'Premium Servis', 'Digital Media',
        'Smart Solar', 'Rapid Transport', 'Top Gastro', 'Elite Design',
        'Nova Pharma', 'Progres Reality', 'Euro Trading',
    ];

    private const SENDER_DOMAINS = [
        'firma.cz', 'obchod.cz', 'sluzby.cz', 'podnik.cz', 'example.cz',
        'test.cz', 'mail.test', 'example.com',
    ];

    private const FIRST_NAMES = [
        'Jan', 'Petr', 'Martin', 'Pavel', 'Tomáš', 'Jana', 'Petra', 'Lenka', 'Eva', 'Kateřina',
    ];

    private const LAST_NAMES = [
        'Novák', 'Svoboda', 'Dvořák', 'Černý', 'Procházka',
        'Nováková', 'Svobodová', 'Dvořáková', 'Černá', 'Procházková',
    ];

    /**
     * Vygeneruje jednu zprávu pro zadanou schránku.
     *
     * @param int    $index               Pořadové číslo zprávy (pro `message_id`)
     * @param int    $mailboxId           PK schránky (pro FK `mailbox`)
     * @param string $defaultPrimaryType  Výchozí primární typ schránky
     * @return array<string, mixed>       Data připravená pro INSERT
     */
    public function generate(int $index, int $mailboxId, string $defaultPrimaryType): array
    {
        // Výběr šablony — zhruba stejný podíl invoice / other, nezávislý na schránce.
        // Schránka pouze řídí default_primary_type; keyword match na subjectu může
        // zvolit jiný typ.
        $isInvoiceTemplate = random_int(1, 100) <= 50;
        $template = $isInvoiceTemplate
            ? self::pick(self::SUBJECT_TEMPLATES_INVOICE)
            : self::pick(self::SUBJECT_TEMPLATES_OTHER);

        $subject = $this->fillTemplate($template, $index);
        $primaryType = $this->detectPrimaryType($subject, $defaultPrimaryType);

        $company = self::pick(self::SENDER_COMPANIES);
        $domain = self::pick(self::SENDER_DOMAINS);
        $senderEmail = strtolower(self::toAscii(str_replace(' ', '', $company))) . '@' . $domain;

        // Display name: buď kontaktní osoba, nebo firma
        $senderName = random_int(0, 1) === 1
            ? self::pick(self::FIRST_NAMES) . ' ' . self::pick(self::LAST_NAMES) . ' (' . $company . ')'
            : $company;

        $receivedAt = $this->randomReceivedAt();
        [$docState, $docStateMain, $analysisState] = $this->randomDocState();

        $now = date('Y-m-d H:i:s');

        return [
            'message_id'            => sprintf(self::ID_PREFIX . '%04d', $index),
            'mailbox'               => $mailboxId,
            'primary_type'          => $primaryType,
            'primary_type_source'   => 'mailbox',
            'analysis_state'        => $analysisState,
            'subject'               => $subject,
            'sender_email'          => $senderEmail,
            'sender_name'           => $senderName,
            'sender_person'         => null,
            'received_at'           => $receivedAt,
            'external_message_id'   => null,
            'in_reply_to'           => null,
            'reply_references'      => null,
            'body_plain'            => $this->generateBody($subject),
            'body_html'             => null,
            'raw_source_attachment' => null,
            'target_table_id'       => null,
            'target_row'            => null,
            'source_type'           => 1, // manual
            'created'               => $now,
            'created_by'            => null,
            'modified'              => $now,
            'docState'              => $docState,
            'docStateMain'          => $docStateMain,
        ];
    }

    private function fillTemplate(string $template, int $index): string
    {
        $count = substr_count($template, '%');
        if ($count === 0) {
            return $template;
        }

        // Šablony mají buď %d, %s, nebo kombinaci — vyplníme věrohodnými hodnotami.
        $baseNumber = 2026_00_00_00 + $index;
        $args = [];
        preg_match_all('/%\w+/', $template, $matches);
        foreach ($matches[0] as $token) {
            $args[] = match (true) {
                str_contains($token, 'd') => $baseNumber + random_int(0, 999),
                str_contains($token, 's') => self::pick(self::CZECH_MONTHS),
                default => '',
            };
        }

        return vsprintf($template, $args);
    }

    private function detectPrimaryType(string $subject, string $default): string
    {
        $lower = self::toAscii(mb_strtolower($subject));
        foreach (self::INVOICE_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return 'invoiceReceived';
            }
        }

        // Žádná shoda → výchozí dle schránky (nebo `other` pokud default nesedí v enabled sadě)
        return in_array($default, ['invoiceReceived', 'other'], true) ? $default : 'other';
    }

    /** Random datetime v posledních 90 dnech. */
    private function randomReceivedAt(): string
    {
        $now = time();
        $offset = random_int(0, 90 * 86400);
        return date('Y-m-d H:i:s', $now - $offset);
    }

    /**
     * Distribuce (upravená §7.2 po oddělení analysis_state):
     *   60 % docState=10 (Nová, mainState=1, ve frontě analýzy)
     *   20 % docState=20 (K řešení, mainState=2, analyzováno)
     *   15 % docState=40 (Hotovo, mainState=3, analyzováno)
     *    5 % docState=80 (Archiv, mainState=4, bez analýzy)
     *
     * @return array{0: int, 1: int, 2: int} [docState, docStateMain, analysisState]
     */
    private function randomDocState(): array
    {
        $r = random_int(1, 100);
        return match (true) {
            $r <= 60 => [10, 1, 10],
            $r <= 80 => [20, 2, 30],
            $r <= 95 => [40, 3, 30],
            default  => [80, 4, 0],
        };
    }

    private function generateBody(string $subject): string
    {
        $greeting = self::pick(['Dobrý den,', 'Vážený zákazníku,', 'Zdravím,', 'Dobré ráno,']);
        $closing = self::pick([
            "S pozdravem,\nOddělení obchodu",
            "Děkujeme za spolupráci,\nTým podpory",
            "Přeji hezký den,\nJan Novák",
            "S úctou,\nFakturační oddělení",
        ]);

        return $greeting . "\n\n"
            . 'v příloze naleznete dokument k předmětu "' . $subject . '". '
            . 'Pokud potřebujete doplňující informace, neváhejte nás kontaktovat.' . "\n\n"
            . $closing;
    }

    /** @template T
     *  @param array<T> $items
     *  @return T */
    private static function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    /** Simplified Czech diacritics removal for email / keyword matching. */
    private static function toAscii(string $str): string
    {
        $map = [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
            'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's',
            'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ];

        return strtr($str, $map);
    }
}
