<?php
use TYPO3\Surf\Domain\Model\Workflow;
use TYPO3\Surf\Domain\Model\Node;
use TYPO3\Surf\Domain\Model\SimpleWorkflow;

// Distribution repository url
if(getenv("REPOSITORY_URL") == "") {
	throw new \TYPO3\Surf\Exception\InvalidConfigurationException("EnvVar REPOSITORY_URL is not set!");
} else {
	$envVars['REPOSITORY_URL'] = getenv("REPOSITORY_URL");
}

// Domain name, used in various places
if(getenv("DOMAIN") == "") {
	throw new \TYPO3\Surf\Exception\InvalidConfigurationException("EnvVar DOMAIN is not set!");
} else {
	$envVars['DOMAIN'] = getenv("DOMAIN");
}

// Ssh port of docker image
if(getenv("PORT") == "") {
	throw new \TYPO3\Surf\Exception\InvalidConfigurationException("EnvVar PORT is not set!");
} else {
	$envVars['PORT'] = getenv("PORT");
}


$application = new \TYPO3\Surf\Application\TYPO3\Flow($envVars['DOMAIN']);
$application->setDeploymentPath('/data/www/'.$envVars['DOMAIN'].'/surf');
$application->setOption('repositoryUrl', $envVars['REPOSITORY_URL']);
$application->setOption('composerCommandPath', '/usr/local/bin/composer');
$application->setOption('keepReleases', 10);
// Use rsync for transfer instead of composer
$application->setOption('transferMethod', 'rsync');
$application->setOption('packageMethod', 'git');
$application->setOption('updateMethod', NULL);
$application->setOption('baseUrl', 'http://' . $envVars['DOMAIN']);
$application->setOption('rsyncFlags', "--recursive --omit-dir-times --no-perms --links --delete --delete-excluded --exclude '.git'");


$workflow = new \TYPO3\Surf\Domain\Model\SimpleWorkflow();
$workflow->setEnableRollback(FALSE);

// Pull from Gerrit mirror instead of git.typo3.org (temporary fix)
$workflow->defineTask('sfi.sfi:nogit',
        'typo3.surf:localshell',
        array('command' => 'git config --global url."http://git.typo3.org".insteadOf git://git.typo3.org')
);
// Apply patches with Beard
$workflow->defineTask('sfi.sfi:beard',
        'typo3.surf:localshell',
        array('command' => 'cd {workspacePath} && git config --global user.email "dimaip@gmail.com" &&  git config --global user.name "Dmitri Pisarev (CircleCI)" && ./beard patch')
);
// Remove resource links since they're absolute symlinks to previous releases (will be generated again automatically)
$workflow->defineTask('sfi.sfi:unsetResourceLinks',
	'typo3.surf:shell',
	array('command' => 'cd {releasePath} && rm -rf Web/_Resources/Persistent/*')
);
// Run build.sh
$workflow->defineTask('sfi.sfi:buildscript',
        'typo3.surf:shell',
        array('command' => 'cd {releasePath} && sh build.sh')
);
// Simple smoke test
$smokeTestOptions = array(
        'url' => 'http://next.'.$envVars['DOMAIN'],
        'remote' => TRUE,
        'expectedStatus' => 200,
        'expectedRegexp' => '/This website is powered by TYPO3 Neos/'
);
$workflow->defineTask('sfi.sfi:smoketest', 'typo3.surf:test:httptest', $smokeTestOptions);
// Clearing opcode cache. More info here: http://codinghobo.com/opcache-and-symlink-based-deployments/		
$workflow->defineTask('sfi.sfi:clearopcache',		
        'typo3.surf:shell',		
        array('command' => 'cd {currentPath}/Web && echo "<?php opcache_reset(); echo \"cache cleared\";" > cc.php && curl "http://' . $envVars['DOMAIN'] . '/cc.php" && rm cc.php')		
);

$workflow->beforeStage('package', 'sfi.sfi:nogit', $application);
$workflow->beforeStage('transfer', 'sfi.sfi:beard', $application);
$workflow->addTask('sfi.sfi:smoketest', 'test', $application);
$workflow->beforeStage('switch', 'sfi.sfi:unsetResourceLinks', $application);
$workflow->afterStage('switch', 'sfi.sfi:clearopcache', $application);
// Caches are cleated in the build script, and that should happen after opcache clear, or images wouldn't get rendered
$workflow->afterStage('switch', 'sfi.sfi:buildscript', $application);

$node = new \TYPO3\Surf\Domain\Model\Node($envVars['DOMAIN']);
$node->setHostname('server.psmb.ru');
$node->setOption('username', 'www');
$node->setOption('port', $envVars['PORT']);
$application->addNode($node);


/** @var \TYPO3\Surf\Domain\Model\Deployment $deployment */
$deployment->setWorkflow($workflow);
$deployment->addApplication($application);

?>
