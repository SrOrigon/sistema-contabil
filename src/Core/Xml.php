<?php

declare(strict_types=1);

namespace App\Core;

final class Xml
{
    public static function documento(string $xml): \DOMDocument
    {
        $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml) ?? $xml;
        $documento = new \DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $ok = $documento->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (!$ok) {
            throw new InvalidXmlException('Trecho de XML inválido.');
        }
        return $documento;
    }

    public static function texto(\DOMNode $no, string $tag): string
    {
        if (!$no instanceof \DOMDocument && !$no instanceof \DOMElement) {
            return '';
        }
        $lista = $no->getElementsByTagName($tag);
        if ($lista->length === 0) {
            return '';
        }
        return trim($lista->item(0)?->textContent ?? '');
    }

    public static function elemento(\DOMNode $no, string $tag): ?\DOMElement
    {
        if (!$no instanceof \DOMDocument && !$no instanceof \DOMElement) {
            return null;
        }
        $lista = $no->getElementsByTagName($tag);
        $item = $lista->item(0);
        return $item instanceof \DOMElement ? $item : null;
    }

    /** @return list<string> */
    public static function textos(\DOMNode $no, string $tag): array
    {
        if (!$no instanceof \DOMDocument && !$no instanceof \DOMElement) {
            return [];
        }
        $valores = [];
        foreach ($no->getElementsByTagName($tag) as $item) {
            $valores[] = trim($item->textContent ?? '');
        }
        return $valores;
    }
}
