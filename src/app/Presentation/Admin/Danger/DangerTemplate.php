<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Danger;

use App\Model\Hall\HallChanges;
use App\Model\Hall\HallPlan;
use App\Presentation\Admin\BaseTemplate;


final class DangerTemplate extends BaseTemplate
{
	/** @var array{svg: string, file_name: string, applied_at: string, applied_by: ?string, hash: string}|null the plan in use */
	public ?array $plan;

	public int $testCount;

	/** Token of the uploaded plan waiting for confirmation. */
	public ?string $uploadToken;

	public ?string $uploadName;

	public ?HallPlan $uploaded;

	/** What the uploaded plan changes; null when it has errors. */
	public ?HallChanges $changes;
}
