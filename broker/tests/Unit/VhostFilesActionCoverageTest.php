<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Actions\VhostFilesAction;
use AzerioidPanel\Broker\Files\VhostFileOp;
use AzerioidPanel\Broker\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Every registered `vhost.files.*` action must reach an operation (B3).
 *
 * This exists because three did not. `copy`, `chmod` and `search` were implemented in
 * VhostFileOp, registered in the Kernel, covered by unit tests that called the op directly,
 * and wired to a UI that talked to FakeBroker — so 964 tests passed while all three were
 * unreachable on a real host: VhostFilesAction's own match had no arm for them and threw
 * "Unknown file manager action."
 *
 * The lesson is the one A1 kept finding: code existing is not the same as code being
 * reachable. This test compares the two lists rather than trusting either.
 */
final class VhostFilesActionCoverageTest extends TestCase
{
    /** @return list<string> */
    private function registeredFileActions(): array
    {
        return array_values(array_filter(
            array_keys(Kernel::ACTIONS),
            static fn (string $action): bool => str_starts_with($action, 'vhost.files.')
                && Kernel::ACTIONS[$action] === VhostFilesAction::class
        ));
    }

    public function test_every_registered_file_action_maps_to_an_operation(): void
    {
        $unreachable = [];
        foreach ($this->registeredFileActions() as $action) {
            $op = substr($action, strlen('vhost.files.'));
            if (!in_array($op, VhostFileOp::OPS, true)) {
                $unreachable[] = $action;
            }
        }

        $this->assertSame([], $unreachable, 'registered but not implemented as an operation');
    }

    /**
     * The action's match is the actual gate, so it is exercised: an action it does not know
     * throws before any operation runs.
     */
    public function test_the_action_accepts_every_registered_file_action(): void
    {
        $rejected = [];
        foreach ($this->registeredFileActions() as $action) {
            try {
                // An empty domain fails validation *after* the action name is resolved, so
                // reaching that error proves the name was accepted.
                (new VhostFilesAction())->handle($action, [''], [], new \AzerioidPanel\Broker\FakeRuntime(), new \AzerioidPanel\Broker\Config());
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'Unknown file manager action')) {
                    $rejected[] = $action;
                }
            }
        }

        $this->assertSame([], $rejected, 'registered in the Kernel but rejected by the action');
    }

    public function test_the_operations_added_in_b3_are_reachable(): void
    {
        foreach (['copy', 'chmod', 'search'] as $op) {
            $this->assertContains($op, VhostFileOp::OPS, $op . ' must be an operation');
            $this->assertArrayHasKey('vhost.files.' . $op, Kernel::ACTIONS, $op . ' must be registered');
        }
    }
}
