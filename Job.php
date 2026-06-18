<?php
// Scheduled task (every minute) of the Lone Worker module.
namespace FreePBX\modules\Loneworker;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class Job implements \FreePBX\Job\TaskInterface {
	public static function run(InputInterface $input, OutputInterface $output) {
		\FreePBX::Loneworker()->tick($output);
		return true;
	}
}
