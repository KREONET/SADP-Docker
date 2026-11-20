<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use App\Support\DevelopmentSshKey;
use Database\Seeders\Concerns\RefusesProductionSeeding;
use Illuminate\Database\Seeder;
use phpseclib3\Crypt\RSA;

class PrivateKeySeeder extends Seeder
{
    use RefusesProductionSeeding;

    /**
     * Run the database seeds.
     *
     * The keys are generated on this machine: a committed private key would be a credential for
     * every host that trusts it. The testing-host key is shared with scripts/dev (.dev/testing-host-key).
     */
    public function run(): void
    {
        $this->refuseToRunInProduction();

        PrivateKey::create([
            'uuid' => 'ssh',
            'team_id' => 0,
            'name' => 'Testing Host Key',
            'description' => 'This is a test docker container',
            'private_key' => DevelopmentSshKey::privateKey(),
        ]);

        PrivateKey::create([
            'uuid' => 'github-key',
            'team_id' => 0,
            'name' => 'development-github-app',
            'description' => 'Placeholder key of the development GitHub app. Replace it with the key of your own GitHub app.',
            'private_key' => RSA::createKey(2048)->toString('PKCS1'),
            'is_git_related' => true,
        ]);
    }
}
