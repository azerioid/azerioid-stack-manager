<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Actions\VhostFilesAction;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Files\VhostFileOp;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Every action registered in the Kernel must be handled by the class it points at.
 *
 * This exists because three were not. `vhost.files.copy`, `.chmod` and `.search` were
 * implemented, registered, covered by unit tests and wired to a working UI — and threw
 * "Unknown file manager action" on every real request for three commits, because
 * VhostFilesAction's own match had no arm for them. 964 tests passed either side of that gap:
 * the broker tests called the operation directly, the panel tests talked to a fake that
 * implements the operations itself, and nothing exercised Kernel -> action -> operation.
 *
 * Most action classes dispatch on the action name a second time internally (a match or switch),
 * so registering a name is not the same as handling it. That second dispatch is the seam, and
 * it is invisible to every other kind of test in this suite.
 *
 * The check is deliberately crude: call each registered action and fail only if it rejects its
 * own name. Any other error — missing arguments, a missing binary, a refused confirmation — means
 * the name was accepted and the action ran, which is all this is asserting.
 */
final class ActionReachabilityTest extends TestCase
{
    /**
     * Wording used by action classes to reject a name they do not know. Matching the message is
     * unavoidable: the alternative is reflecting over a match expression.
     */
    private const REJECTION_PATTERNS = [
        'unknown action',
        'unsupported action',
        'unknown file manager action',
        'unknown firewall action',
        'unsupported firewall action',
        'unknown cron action',
        'unknown mail action',
        'unknown vhost action',
        'unknown backup action',
        'unknown panel action',
        'unknown component action',
        'unsupported backup action',
    ];

    private function rejectsItsOwnName(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        foreach (self::REJECTION_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function test_every_registered_action_is_handled_by_its_class(): void
    {
        $unreachable = [];
        $exercised = 0;

        foreach (Kernel::ACTIONS as $action => $class) {
            if (!class_exists($class) || !method_exists($class, 'handle')) {
                $unreachable[] = $action . ' -> ' . $class . ' (no handle())';
                continue;
            }
            $exercised++;
            try {
                (new $class())->handle($action, [], [], new FakeRuntime(), new Config());
            } catch (\Throwable $e) {
                if ($this->rejectsItsOwnName($e)) {
                    $unreachable[] = $action . ' -> ' . $class . ': ' . $e->getMessage();
                }
                // Anything else means the name was accepted; that is all this asserts.
            }
        }

        $this->assertGreaterThan(100, $exercised, 'the registry should not have shrunk to nothing');
        $this->assertSame([], $unreachable, 'registered in the Kernel but rejected by its own handler');
    }

    /**
     * The file manager keeps a second list — VhostFileOp::OPS — so the two are compared rather
     * than either being trusted. This is the specific pairing that drifted.
     */
    public function test_file_actions_map_to_an_implemented_operation(): void
    {
        $missing = [];
        foreach (Kernel::ACTIONS as $action => $class) {
            if ($class !== VhostFilesAction::class) {
                continue;
            }
            $op = substr($action, strlen('vhost.files.'));
            if (!in_array($op, VhostFileOp::OPS, true)) {
                $missing[] = $action;
            }
        }

        $this->assertSame([], $missing, 'registered but not implemented as an operation');
    }

    public function test_the_operations_added_in_b3_are_reachable(): void
    {
        foreach (['copy', 'chmod', 'search', 'zip'] as $op) {
            $this->assertContains($op, VhostFileOp::OPS, $op . ' must be an operation');
            $this->assertArrayHasKey('vhost.files.' . $op, Kernel::ACTIONS, $op . ' must be registered');
        }
    }
}
