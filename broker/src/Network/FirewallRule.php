<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Network;

use AzerioidPanel\Broker\BrokerException;

/**
 * One firewall rule, in the only shape the panel deals in (B2 / request #2).
 *
 * Both backends can express far more than this. ufw has routed rules, interface
 * matching and rate limits; firewalld has zones, direct rules and policies. The
 * panel deliberately models the intersection an operator actually needs — allow or
 * deny a port, optionally from one source — because a model that can express
 * everything is a model whose two drivers cannot be kept equivalent, and the whole
 * point of B2 is that a rule means the same thing on Debian and on Rocky.
 *
 * Anything more complex is left to the operator's own `ufw`/`firewall-cmd`, shown
 * as unmanaged in the listing and never rewritten.
 */
final class FirewallRule
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    /** Marks rules the panel wrote, in the comment on ufw and in the rich rule on firewalld. */
    public const TAG = 'azerioid';

    private function __construct(
        public readonly string $action,
        public readonly int $port,
        public readonly string $protocol,
        public readonly ?string $source,
        public readonly string $comment,
        public readonly bool $managed,
        /** Backend-specific handle used to delete exactly this rule again. */
        public readonly ?string $handle = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $action = strtolower(trim((string) ($input['action'] ?? self::ALLOW)));
        if (!in_array($action, [self::ALLOW, self::DENY], true)) {
            throw new BrokerException('Rule action must be allow or deny.', 2);
        }

        $port = (int) ($input['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new BrokerException('Port must be between 1 and 65535.', 2);
        }

        $protocol = strtolower(trim((string) ($input['protocol'] ?? 'tcp')));
        if (!in_array($protocol, ['tcp', 'udp'], true)) {
            throw new BrokerException('Protocol must be tcp or udp.', 2);
        }

        $source = trim((string) ($input['source'] ?? ''));
        $source = $source === '' || strtolower($source) === 'any' ? null : self::normalizeSource($source);

        $note = self::normalizeNote((string) ($input['note'] ?? ''));

        return new self(
            $action,
            $port,
            $protocol,
            $source,
            self::TAG . ($note === '' ? '' : '-' . $note),
            true
        );
    }

    /**
     * A rule read back off the host. `managed` is decided by the tag, not by
     * whether the panel happens to remember writing it: a reinstalled panel must
     * still recognise its own rules, and an operator's hand-written rule must never
     * be mistaken for one.
     */
    public static function observed(
        string $action,
        int $port,
        string $protocol,
        ?string $source,
        string $comment,
        ?string $handle = null,
    ): self {
        return new self(
            $action,
            $port,
            $protocol,
            $source,
            $comment,
            $comment === self::TAG || str_starts_with($comment, self::TAG . '-'),
            $handle
        );
    }

    /**
     * IPv4/IPv6 address or CIDR. Hostnames are refused: they resolve at rule-write
     * time and a rule that silently means something else after a DNS change is not
     * a firewall rule an operator can reason about.
     */
    private static function normalizeSource(string $source): string
    {
        [$address, $prefix] = array_pad(explode('/', $source, 2), 2, null);
        $address = (string) $address;
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new BrokerException('Source must be an IP address or CIDR range, not a hostname.', 2);
        }
        if ($prefix === null) {
            return $address;
        }
        $max = str_contains($address, ':') ? 128 : 32;
        if (!ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > $max) {
            throw new BrokerException('CIDR prefix must be between 0 and ' . $max . '.', 2);
        }

        return $address . '/' . (int) $prefix;
    }

    /**
     * The note ends up inside a ufw comment and a firewalld rich rule, so it is
     * restricted to characters that cannot terminate or extend either syntax. It is
     * a label, not a description.
     */
    private static function normalizeNote(string $note): string
    {
        $note = strtolower(trim($note));
        if ($note === '') {
            return '';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $note)) {
            throw new BrokerException('Note may only contain letters, digits and hyphens (max 31).', 2);
        }

        return $note;
    }

    public function describe(): string
    {
        return $this->action . ' ' . $this->port . '/' . $this->protocol
            . ' from ' . ($this->source ?? 'any');
    }

    /** Identity for comparison and de-duplication; the comment is not part of it. */
    public function key(): string
    {
        return $this->action . ':' . $this->port . ':' . $this->protocol . ':' . ($this->source ?? 'any');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'source' => $this->source,
            'comment' => $this->comment,
            'managed' => $this->managed,
            'describe' => $this->describe(),
        ];
    }
}
