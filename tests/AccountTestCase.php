<?php

namespace Tests;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Testing\TestResponse;
use OGame\Models\Resources;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Base class for tests that require account context. Common setup includes signup of new account and login.
 */
abstract class AccountTestCase extends TestCase
{
    protected int $currentUserId = 0;
    protected string $currentUsername = '';
    protected int $currentPlanetId = 0;
    protected PlanetService $secondPlanetService;

    /**
     * Set up common test components.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create a new user and login so we can access ingame features.
        $this->createAndLoginUser();

        // We should now automatically be logged in. Retrieve meta fields to verify.
        $this->retrieveMetaFields();
    }

    /**
     * Retrieve meta fields from page response to extract player id and planet id.
     *
     * @return void
     * @throws BindingResolutionException
     */
    protected function retrieveMetaFields(): void
    {
        //  Extract current user planet ID based on meta tag in the overview page
        $response = $this->get('/overview');
        $content = $response->getContent();
        if (empty($content)) {
            $content = '';
        }

        if ($response->status() !== 200) {
            // Return first 2k chars for debug purposes.
            $this->fail('Failed to retrieve overview page after registration. Response HTTP code: ' . $response->status() . '. Response first 2k chars: ' . substr($content, 0, 2000));
        }

        preg_match('/<meta name="ogame-player-id" content="([^"]+)"/', $content, $playerIdMatches);
        preg_match('/<meta name="ogame-player-name" content="([^"]+)"/', $content, $playerNameMatches);
        preg_match('/<meta name="ogame-planet-id" content="([^"]+)"/', $content, $planetIdMatches);

        $playerId = $playerIdMatches[1] ?? null;
        $playerName = $playerNameMatches[1] ?? null;
        $planetId = $planetIdMatches[1] ?? null;

        // Now you can assert these values to ensure they are what you expect.
        $this->assertNotEmpty($playerId);
        $this->assertNotEmpty($playerName);
        $this->assertNotEmpty($planetId);

        $this->currentUserId = (int)$playerId;
        $this->currentUsername = $playerName;
        $this->currentPlanetId = (int)$planetId;

        $playerService = app()->make(PlayerService::class, ['player_id' => $this->currentUserId]);
        $this->planetService = $playerService->planets->current();
        $this->secondPlanetService = $playerService->planets->all()[1];
    }

    /**
     * Get a random second user id from the database. This is useful for testing interactions between two players.
     *
     * @return int
     */
    protected function getSecondPlayerId(): int
    {
        $playerIds = \DB::table('users')->whereNot('id', $this->currentUserId)->inRandomOrder()->limit(1)->pluck('id');
        if (count($playerIds) < 1) {
            // Create user if there are not enough in the database.
            $this->createAndLoginUser();
            $playerIds = \DB::table('users')->whereNot('id', $this->currentUserId)->inRandomOrder()->limit(1)->pluck('id');
        }

        return $playerIds[0];
    }


    /**
     * Add resources to current users current planet.
     *
     * @param Resources $resources
     * @return void
     */
    protected function planetAddResources(Resources $resources): void
    {
        // Update resources.
        $this->planetService->addResources($resources, true);
    }

    /**
     * Deduct resources from current users current planet.
     *
     * @param Resources $resources
     * @return void
     * @throws BindingResolutionException
     * @throws Exception
     */
    protected function planetDeductResources(Resources $resources): void
    {
        // Update resources.
        $this->planetService->deductResources($resources);
    }

    /**
     * Set object level on current users current planet.
     *
     * @param string $machine_name
     * @param int $object_level
     * @return void
     * @throws BindingResolutionException
     * @throws Exception
     */
    protected function planetSetObjectLevel(string $machine_name, int $object_level): void
    {
        // Update the object level on the planet.
        $object = $this->planetService->objects->getObjectByMachineName($machine_name);
        $this->planetService->setObjectLevel($object->id, $object_level, true);
    }

    /**
     * Add units to current users current planet.
     *
     * @param string $machine_name
     * @param int $amount
     * @return void
     * @throws BindingResolutionException
     * @throws Exception
     */
    protected function planetAddUnit(string $machine_name, int $amount): void
    {
        // Update the object level on the planet.
        $this->planetService->addUnit($machine_name, $amount);
    }

    /**
     * Set object level on current users current planet.
     *
     * @param string $machine_name
     * @param int $object_level
     * @return void
     * @throws BindingResolutionException
     */
    protected function playerSetResearchLevel(string $machine_name, int $object_level): void
    {
        // Update current users planet buildings to allow for research by mutating database.
        $playerService = app()->make(PlayerService::class, ['player_id' => $this->currentUserId]);
        // Update the technology level for the player.
        $playerService->setResearchLevel($machine_name, $object_level, true);
    }

    /**
     * Assert that the object level is as expected on the page.
     *
     * @param TestResponse $response
     * @param string $machine_name
     * @param int $expected_level
     * @param string $error_message
     * @return void
     */
    protected function assertObjectLevelOnPage(TestResponse $response, string $machine_name, int $expected_level, string $error_message = ''): void
    {
        // Assert response is successful
        $response->assertStatus(200);

        // Get object name from machine name.
        try {
            $object = $this->planetService->objects->getObjectByMachineName($machine_name);
        } catch (Exception $e) {
            $this->fail('Failed to get object by machine name: ' . $machine_name . '. Error: ' . $e->getMessage());
        }

        // Update pattern to extract level from data-value attribute
        $pattern = '/<li[^>]*\bclass="[^"]*\b' . preg_quote($object->class_name, '/') . '\b[^"]*"[^>]*>.*?<span[^>]+class="(?:level|amount)"[^>]*data-value="(\d+)"[^>]*>/s';

        $content = $response->getContent();
        if (empty($content)) {
            $content = '';
        }
        if (preg_match($pattern, $content, $matches)) {
            $actual_level = $matches[1];  // The captured digits from data-value
            if (!empty($error_message)) {
                $this->assertEquals($expected_level, $actual_level, $error_message);
            } else {
                $this->assertEquals($expected_level, $actual_level, $object->title . ' is at level (' . $actual_level . ') while it is expected to be at level (' . $expected_level . ').');
            }
        } else {
            $this->fail('No matching level found on page for object ' . $object->title);
        }
    }


    /**
     * Assert that the resources are as expected on the page.
     *
     * @param TestResponse $response
     * @param Resources $resources
     * @return void
     */
    protected function assertResourcesOnPage(TestResponse $response, Resources $resources): void{
        $content = $response->getContent();
        if (empty($content)) {
            $content = '';
        }

        if ($resources->metal->get() > 0) {
            $pattern = '/<span id="resources_metal" class="[^"]*" data-raw="[^"]*">\s*' . $resources->metal->getFormattedLong() . '\s*<\/span>/';
            $result = preg_match($pattern, $content);
            $this->assertTrue($result === 1, 'Resource metal is not at ' . $resources->metal->getFormattedLong() . '.');
        }
        if ($resources->crystal->get() > 0) {
            $pattern = '/<span\s+id="resources_crystal" class="[^"]*" data-raw="[^"]*">\s*' . $resources->crystal->getFormattedLong() . '\s*<\/span>/';
            $result = preg_match($pattern, $content);
            $this->assertTrue($result === 1, 'Resource crystal is not at ' . $resources->crystal->getFormattedLong() . '.');
        }

        if ($resources->deuterium->get() > 0) {
            $pattern = '/<span\s+id="resources_deuterium" class="[^"]*" data-raw="[^"]*">\s*' . $resources->deuterium->getFormattedLong() . '\s*<\/span>/';
            $result = preg_match($pattern, $content);
            $this->assertTrue($result === 1, 'Resource deuterium is not at ' . $resources->deuterium->getFormattedLong() . '.');
        }

        if ($resources->energy->get() > 0) {
            $pattern = '/<span\s+id="resources_energy" class="[^"]*" data-raw="[^"]*">\s*' . $resources->energy->getFormattedLong() . '\s*<\/span>/';
            $result = preg_match($pattern, $content);
            $this->assertTrue($result === 1, 'Resource energy is not at ' . $resources->energy->getFormattedLong() . '.');
        }
    }

    protected function assertObjectInQueue(TestResponse $response, string $machine_name, string $error_message = ''): void
    {
        // Get object name from machine name.
        try {
            $object = $this->planetService->objects->getObjectByMachineName($machine_name);
        } catch (Exception $e) {
            $this->fail('Failed to get object by machine name: ' . $machine_name . '. Error: ' . $e->getMessage());
        }

        // Check if cancel text is present on page.
        try {
            $response->assertSee('Cancel production of ' . $object->title);
        }
        catch (Exception $e) {
            if (!empty($error_message)) {
                $this->fail($error_message . '. Error: ' . $e->getMessage());
            } else {
                $this->fail('Object ' . $object->title . ' is not in the queue. Error: ' . $e->getMessage());
            }
        }
    }

    protected function assertObjectNotInQueue(TestResponse $response, string $machine_name, string $error_message = ''): void
    {
        // Get object name from machine name.
        try {
            $object = $this->planetService->objects->getObjectByMachineName($machine_name);
        } catch (Exception $e) {
            $this->fail('Failed to get object by machine name: ' . $machine_name . '. Error: ' . $e->getMessage());
        }

        // Check if cancel text is present on page.
        try {
            $response->assertDontSee('Cancel production of ' . $object->title);
        }
        catch (Exception $e) {
            if (!empty($error_message)) {
                $this->fail($error_message . '. Error: ' . $e->getMessage());
            } else {
                $this->fail('Object ' . $object->title . ' is not in the queue. Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Add a resource build request to the current users current planet.
     * @param string $machine_name
     * @param bool $ignoreErrors
     * @return void
     * @throws Exception
     */
    protected function addResourceBuildRequest(string $machine_name, bool $ignoreErrors = false): void
    {
        $object = $this->planetService->objects->getObjectByMachineName($machine_name);

        $response = $this->post('/resources/add-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $object->id,
        ]);

        if ($ignoreErrors) {
            return;
        }

        // Assert the response status is successful
        $response->assertStatus(200);
    }

    /**
     * Cancel a resource build request on the current users current planet.
     *
     * @param int $objectId
     * @param int $buildQueueId
     * @return void
     */
    protected function cancelResourceBuildRequest(int $objectId, int $buildQueueId): void
    {
        $response = $this->post('/resources/cancel-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $objectId,
            'listId' => $buildQueueId,
        ]);

        // Assert the response status is successful
        $response->assertStatus(200);
    }

    /**
     * Add a facilities build request to the current users current planet.
     * @param string $machine_name
     * @return void
     * @throws Exception
     */
    protected function addFacilitiesBuildRequest(string $machine_name): void
    {
        $object = $this->planetService->objects->getObjectByMachineName($machine_name);

        $response = $this->post('/facilities/add-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $object->id,
        ]);
        // Assert the response status is successful
        $response->assertStatus(200);
    }

    /**
     * Cancel a facilities build request on the current users current planet.
     *
     * @param int $objectId
     * @param int $buildQueueId
     * @return void
     */
    protected function cancelFacilitiesBuildRequest(int $objectId, int $buildQueueId): void
    {
        $response = $this->post('/facilities/cancel-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $objectId,
            'listId' => $buildQueueId,
        ]);

        // Assert the response status is successful
        $response->assertStatus(200);
    }

    /**
     * Add a research build request to the current users current planet.
     * @param string $machine_name
     * @return void
     * @throws Exception
     */
    protected function addResearchBuildRequest(string $machine_name): void
    {
        $object = $this->planetService->objects->getObjectByMachineName($machine_name);

        $response = $this->post('/research/add-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $object->id,
        ]);
        // Assert the response status is successful
        $response->assertStatus(200);
    }

    /**
     * Cancel a research build request on the current users current planet.
     *
     * @param int $objectId
     * @param int $buildQueueId
     * @return void
     */
    protected function cancelResearchBuildRequest(int $objectId, int $buildQueueId): void
    {
        $response = $this->post('/research/cancel-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $objectId,
            'listId' => $buildQueueId,
        ]);

        // Assert the response status is successful
        $response->assertStatus(200);
    }

    /**
     * Add a shipyard build request to the current users current planet.
     * @param string $machine_name
     * @param int $amount
     * @return void
     * @throws Exception
     */
    protected function addShipyardBuildRequest(string $machine_name, int $amount): void
    {
        $object = $this->planetService->objects->getObjectByMachineName($machine_name);

        $response = $this->post('/shipyard/add-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $object->id,
            'amount' => $amount,
        ]);

        // Assert the response status is successful
        $response->assertStatus(200);
    }

    /**
     * Add a defense build request to the current users current planet.
     * @param string $machine_name
     * @param int $amount
     * @return void
     * @throws Exception
     */
    protected function addDefenseBuildRequest(string $machine_name, int $amount): void
    {
        $object = $this->planetService->objects->getObjectByMachineName($machine_name);

        $response = $this->post('/defense/add-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $object->id,
            'amount' => $amount,
        ]);

        // Assert the response status is successful
        $response->assertStatus(200);
    }

    /**
     * View the messages page for the current user in order to mark all default system
     * messages as read.
     *
     * @return void
     */
    protected function playerSetAllMessagesRead(): void
    {
        // Access the main messages page where default register message is sent to
        // in order to mark all messages as read.
        $response = $this->get('/ajax/messages?tab=universe');
        // Assert the response status is successful
        $response->assertStatus(200);
    }

    /**
     * Asserts that a message has been received in the specified tab/subtab and that it contains the specified text.
     *
     * @param string $tab
     * @param string $subtab
     * @param array<int,string> $must_contain
     * @return void
     */
    protected function assertMessageReceivedAndContains(string $tab, string $subtab, array $must_contain) : void {
        // Assert that message has been sent to player.
        $response = $this->get('/overview');
        // Assert that page contains "1 unread message(s)" text.
        $response->assertSee('1 unread message(s)');
        $response = $this->get('/ajax/messages?tab=' . $tab . '&subtab=' . $subtab);
        $response->assertStatus(200);
        foreach ($must_contain as $needle) {
            $response->assertSee($needle, false);
        }
    }
}
