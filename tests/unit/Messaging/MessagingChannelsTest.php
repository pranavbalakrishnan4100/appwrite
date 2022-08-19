<?php

namespace Tests\Unit\Messaging;

use Appwrite\Auth\Auth;
use Utopia\Database\Document;
use Appwrite\Messaging\Adapter\Realtime;
use PHPUnit\Framework\TestCase;
use Utopia\Database\ID;
use Utopia\Database\Role;

class MessagingChannelsTest extends TestCase
{
    /**
     * Configures how many Connections the Test should Mock.
     */
    public $connectionsPerChannel = 10;

    public Realtime $realtime;
    public $connectionsCount = 0;
    public $connectionsAuthenticated = 0;
    public $connectionsGuest = 0;
    public $connectionsTotal = 0;
    public $allChannels = [
        'files',
        'files.1',
        'collections',
        'collections.1',
        'collections.1.documents',
        'documents',
        'documents.1',
        'executions',
        'executions.1',
        'functions.1',
    ];

    public function setUp(): void
    {
        /**
         * Setup global Counts
         */
        $this->connectionsAuthenticated = count($this->allChannels) * $this->connectionsPerChannel;
        $this->connectionsGuest = count($this->allChannels) * $this->connectionsPerChannel;
        $this->connectionsTotal = $this->connectionsAuthenticated + $this->connectionsGuest;

        $this->realtime = new Realtime();

        /**
         * Add Authenticated Clients
         */
        for ($i = 0; $i < $this->connectionsPerChannel; $i++) {
            foreach ($this->allChannels as $index => $channel) {
                $user = new Document([
                    '$id' => ID::custom('user' . $this->connectionsCount),
                    'memberships' => [
                        [
                            'teamId' => ID::custom('team' . $i),
                            'roles' => [
                                empty($index % 2)
                                    ? Auth::USER_ROLE_ADMIN
                                    : Role::users()->toString(),
                            ]
                        ]
                    ]
                ]);

                $roles = Auth::getRoles($user);

                $parsedChannels = Realtime::convertChannels([0 => $channel], $user->getId());

                $this->realtime->subscribe(
                    '1',
                    $this->connectionsCount,
                    $roles,
                    $parsedChannels
                );

                $this->connectionsCount++;
            }
        }

        /**
         * Add Guest Clients
         */
        for ($i = 0; $i < $this->connectionsPerChannel; $i++) {
            foreach ($this->allChannels as $index => $channel) {
                $user = new Document([
                    '$id' => ''
                ]);

                $roles = Auth::getRoles($user);

                $parsedChannels = Realtime::convertChannels([0 => $channel], $user->getId());

                $this->realtime->subscribe(
                    '1',
                    $this->connectionsCount,
                    $roles,
                    $parsedChannels
                );

                $this->connectionsCount++;
            }
        }
    }

    public function tearDown(): void
    {
        unset($this->realtime);
        $this->connectionsCount = 0;
    }

    public function testSubscriptions(): void
    {
        /**
         * Check for 1 project.
         */
        $this->assertCount(1, $this->realtime->subscriptions);

        /**
         * Check for correct amount of subscriptions:
         *  - XXX users
         *  - XXX teams
         *  - XXX team roles (2 roles per team)
         *  - 1 guests
         *  - 1 users
         */
        $this->assertCount(($this->connectionsAuthenticated + (3 * $this->connectionsPerChannel) + 2), $this->realtime->subscriptions['1']);

        /**
         * Check for connections
         *  - Authenticated
         *  - Guests
         */
        $this->assertCount($this->connectionsTotal, $this->realtime->connections);

        $this->realtime->unsubscribe(-1);

        $this->assertCount($this->connectionsTotal, $this->realtime->connections);
        $this->assertCount(($this->connectionsAuthenticated + (3 * $this->connectionsPerChannel) + 2), $this->realtime->subscriptions['1']);

        for ($i = 0; $i < $this->connectionsCount; $i++) {
            $this->realtime->unsubscribe($i);

            $this->assertCount(($this->connectionsCount - $i - 1), $this->realtime->connections);
        }

        $this->assertEmpty($this->realtime->connections);
        $this->assertEmpty($this->realtime->subscriptions);
    }

    /**
     * Tests Wildcard ("any") Permissions on every channel.
     */
    public function testWildcardPermission(): void
    {
        foreach ($this->allChannels as $index => $channel) {
            $event = [
                'project' => '1',
                'roles' => [Role::any()->toString()],
                'data' => [
                    'channels' => [
                        0 => $channel,
                    ]
                ]
            ];

            $receivers = $this->realtime->getSubscribers($event);

            /**
             * Every Client subscribed to the Wildcard should receive this event.
             */
            $this->assertCount($this->connectionsTotal / count($this->allChannels), $receivers, $channel);

            foreach ($receivers as $receiver) {
                /**
                 * Making sure the right clients receive the event.
                 */
                $this->assertStringEndsWith($index, $receiver);
            }
        }
    }

    public function testRolePermissions(): void
    {
        $roles = [
            Role::guests()->toString(),
            Role::users()->toString()
        ];
        foreach ($this->allChannels as $index => $channel) {
            foreach ($roles as $role) {
                $permissions = [$role];

                $event = [
                    'project' => '1',
                    'roles' => $permissions,
                    'data' => [
                        'channels' => [
                            0 => $channel,
                        ]
                    ]
                ];

                $receivers = $this->realtime->getSubscribers($event);

                /**
                 * Every Role subscribed to a Channel should receive this event.
                 */
                $this->assertCount($this->connectionsPerChannel, $receivers, $channel);

                foreach ($receivers as $receiver) {
                    /**
                     * Making sure the right clients receive the event.
                     */
                    $this->assertStringEndsWith($index, $receiver);
                }
            }
        }
    }

    public function testUserPermissions(): void
    {
        foreach ($this->allChannels as $index => $channel) {
            $permissions = [];
            for ($i = 0; $i < $this->connectionsPerChannel; $i++) {
                $permissions[] = Role::user(ID::custom('user' . (!empty($i) ? $i : '') . $index))->toString();
            }
            $event = [
                'project' => '1',
                'roles' => $permissions,
                'data' => [
                    'channels' => [
                        0 => $channel,
                    ]
                ]
            ];

            $receivers = $this->realtime->getSubscribers($event);

            /**
             * Every Client subscribed to a Channel should receive this event.
             */
            $this->assertCount($this->connectionsAuthenticated / count($this->allChannels), $receivers, $channel);

            foreach ($receivers as $receiver) {
                /**
                 * Making sure the right clients receive the event.
                 */
                $this->assertStringEndsWith($index, $receiver);
            }
        }
    }

    public function testTeamPermissions(): void
    {
        foreach ($this->allChannels as $index => $channel) {
            $permissions = [];

            for ($i = 0; $i < $this->connectionsPerChannel; $i++) {
                $permissions[] = Role::team(ID::custom('team' . $i))->toString();
            }
            $event = [
                'project' => '1',
                'roles' => $permissions,
                'data' => [
                    'channels' => [
                        0 => $channel,
                    ]
                ]
            ];

            $receivers = $this->realtime->getSubscribers($event);

            /**
             * Every Team Member should receive this event.
             */
            $this->assertCount($this->connectionsAuthenticated / count($this->allChannels), $receivers, $channel);

            foreach ($receivers as $receiver) {
                /**
                 * Making sure the right clients receive the event.
                 */
                $this->assertStringEndsWith($index, $receiver);
            }

            $permissions = [
                Role::team(
                    ID::custom('team' . $index),
                    (empty($index % 2)
                        ? Auth::USER_ROLE_ADMIN
                        : Role::users()->toString())
                )->toString()
            ];

            $event = [
                'project' => '1',
                'roles' => $permissions,
                'data' => [
                    'channels' => [
                        0 => $channel,
                    ]
                ]
            ];

            $receivers = $this->realtime->getSubscribers($event);

            /**
             * Only 1 Team Member of a role should have access to a specific channel.
             */
            $this->assertCount(1, $receivers, $channel);

            foreach ($receivers as $receiver) {
                /**
                 * Making sure the right clients receive the event.
                 */
                $this->assertStringEndsWith($index, $receiver);
            }
        }
    }
}
