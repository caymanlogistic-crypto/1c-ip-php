<?php
declare(strict_types=1);

final class ProbeFailure extends RuntimeException {}

/** Bounded read-only diagnostics. Bodies and credentials never enter reports. */
final class OnecProbe
{
    public const HOST = '1c.dltkmsk.ru';
    public const IP = '89.208.213.32';
    public const ROOT = '/yakovlev_odata/odata/standard.odata/';
    private array $requests = [];
    private float $deadline;
    private int $bytes = 0;
    private $transport;

    public function __construct(?callable $transport = null) { $this->transport = $transport; }

    public static function xml(string $body): DOMDocument
    {
        if (str_contains($body, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $body)) throw new ProbeFailure('UNSAFE_XML');
        $old = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            if (!$doc->loadXML($body, LIBXML_NONET | LIBXML_COMPACT)) throw new ProbeFailure('INVALID_XML');
            return $doc;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }

    public static function service(string $body): array
    {
        $doc = self::xml($body);
        if ($doc->documentElement?->localName !== 'service' || $doc->documentElement->namespaceURI !== 'http://www.w3.org/2007/app') throw new ProbeFailure('INVALID_SERVICE');
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('app', 'http://www.w3.org/2007/app');
        $names = [];
        foreach ($xp->query('/app:service/app:workspace/app:collection') as $node) {
            $name = $node->getAttribute('href');
            if (!preg_match('/^[\p{L}\p{N}_]+$/uD', $name) || isset($names[$name])) throw new ProbeFailure('INVALID_COLLECTION_LINK');
            $names[$name] = true;
        }
        if (!$names) throw new ProbeFailure('NO_PUBLISHED_COLLECTIONS');
        return array_keys($names);
    }

    public static function metadata(string $body): array
    {
        $doc = self::xml($body);
        if ($doc->documentElement?->localName !== 'Edmx' || $doc->documentElement->namespaceURI !== 'http://schemas.microsoft.com/ado/2007/06/edmx') throw new ProbeFailure('INVALID_METADATA');
        $types = []; $sets = []; $aliases = [];
        foreach ($doc->getElementsByTagNameNS('*', 'Schema') as $schema) {
            if (!in_array($schema->namespaceURI, ['http://schemas.microsoft.com/ado/2009/11/edm', 'http://schemas.microsoft.com/ado/2008/09/edm'], true)) continue;
            if ($schema->hasAttribute('Alias')) $aliases[$schema->getAttribute('Alias')] = $schema->getAttribute('Namespace');
            foreach ($schema->childNodes as $type) {
                if (!$type instanceof DOMElement || $type->localName !== 'EntityType') continue;
                $props = []; $keys = [];
                foreach ($type->childNodes as $node) {
                    if (!$node instanceof DOMElement) continue;
                    if ($node->localName === 'Property') $props[] = $node->getAttribute('Name');
                    if ($node->localName === 'Key') foreach ($node->getElementsByTagNameNS($schema->namespaceURI, 'PropertyRef') as $ref) $keys[] = $ref->getAttribute('Name');
                }
                if (array_diff($keys, $props)) throw new ProbeFailure('INVALID_METADATA_KEY');
                $types[$schema->getAttribute('Namespace') . '.' . $type->getAttribute('Name')] = ['properties' => $props, 'keys' => $keys];
            }
            foreach ($schema->getElementsByTagNameNS($schema->namespaceURI, 'EntitySet') as $set) {
                $name = $set->getAttribute('Name');
                if (isset($sets[$name])) throw new ProbeFailure('DUPLICATE_ENTITYSET');
                $sets[$name] = $set->getAttribute('EntityType');
            }
        }
        $result = [];
        foreach ($sets as $name => $type) {
            $dot = strrpos($type, '.');
            if ($dot !== false) $type = ($aliases[substr($type, 0, $dot)] ?? substr($type, 0, $dot)) . substr($type, $dot);
            if (!isset($types[$type])) throw new ProbeFailure('UNKNOWN_ENTITY_TYPE');
            $result[$name] = $types[$type];
        }
        if (!$result) throw new ProbeFailure('EMPTY_METADATA');
        return $result;
    }

    public static function sample(string $body, array $keys): int
    {
        $doc = self::xml($body);
        if ($doc->documentElement?->localName !== 'feed' || $doc->documentElement->namespaceURI !== 'http://www.w3.org/2005/Atom') throw new ProbeFailure('INVALID_ATOM_FEED');
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('a', 'http://www.w3.org/2005/Atom');
        $xp->registerNamespace('m', 'http://schemas.microsoft.com/ado/2007/08/dataservices/metadata');
        $entries = $xp->query('/a:feed/a:entry');
        if ($entries->length > 1 || $xp->query('//a:link[@rel="next"]')->length) throw new ProbeFailure('SAMPLE_LIMIT_EXCEEDED');
        foreach ($entries as $entry) {
            $properties = $xp->query('a:content/m:properties', $entry);
            if ($properties->length !== 1) throw new ProbeFailure('INVALID_SAMPLE_PROPERTIES');
            $seen = [];
            foreach ($properties->item(0)->childNodes as $prop) {
                if (!$prop instanceof DOMElement) continue;
                if ($prop->namespaceURI !== 'http://schemas.microsoft.com/ado/2007/08/dataservices' || !in_array($prop->localName, $keys, true) || isset($seen[$prop->localName])) throw new ProbeFailure('UNSELECTED_SAMPLE_PROPERTY');
                $seen[$prop->localName] = true;
            }
            if (array_diff($keys, array_keys($seen))) throw new ProbeFailure('SAMPLE_KEY_MISSING');
        }
        return $entries->length;
    }

    public static function basicChallenge(array $headers): bool
    {
        foreach ($headers as $header) {
            // Remove quoted strings before recognizing auth scheme boundaries.
            $schemes = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $header);
            if (preg_match('/(?:^|,)\s*Basic(?:\s|$)/i', $schemes)) return true;
        }
        return false;
    }

}
