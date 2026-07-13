<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

/**
 * Minimální renderer mailových šablon: soubor + strtr placeholders, žádná
 * logika. Šablona `{name}` v jazyce `{lang}` jsou dva soubory v adresáři
 * šablon: `{lang}/{name}.txt` (první řádek `Subject: ...`, zbytek plain text)
 * a `{lang}/{name}.html` (jen body). Chybějící jazyk padá na `en`.
 * Placeholders se píší `{jméno}` a nahrazují se doslovně.
 */
final class MailTemplate
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /**
     * @param array<string, string> $vars placeholder => hodnota (bez závorek)
     *
     * @return array{subject: string, text: string, html: string}
     */
    public function render(string $name, string $lang, array $vars): array
    {
        $txtFile = $this->resolve($name . '.txt', $lang);
        $htmlFile = $this->resolve($name . '.html', $lang);

        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }

        $txt = strtr(file_get_contents($txtFile), $replacements);
        $html = strtr(file_get_contents($htmlFile), $replacements);

        // Subject: první řádek textové varianty s prefixem `Subject: `.
        [$firstLine, $body] = explode("\n", $txt, 2);
        if (!str_starts_with($firstLine, 'Subject:')) {
            throw new \RuntimeException("Mail template '{$name}' ({$lang}): first line of .txt must start with 'Subject:'");
        }

        return [
            'subject' => trim(substr($firstLine, strlen('Subject:'))),
            'text'    => ltrim($body, "\n"),
            'html'    => $html,
        ];
    }

    private function resolve(string $file, string $lang): string
    {
        foreach (array_unique([$lang, 'en']) as $candidate) {
            $path = $this->templateDir . '/' . $candidate . '/' . $file;
            if (is_file($path)) {
                return $path;
            }
        }

        throw new \RuntimeException("Mail template file not found: {$file} (lang {$lang}, dir {$this->templateDir})");
    }
}
