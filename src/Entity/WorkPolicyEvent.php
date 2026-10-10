<?php
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'work_policy_event')]
#[ORM\Index(name: 'idx_work_event_case', columns: ['finding_id', 'changed_at'])]
#[ORM\Index(name: 'idx_work_event_host', columns: ['hostname', 'changed_at'])]
class WorkPolicyEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;
    #[ORM\Column(length: 36)]
    public string $findingId;
    #[ORM\Column(length: 255)]
    public string $hostname;
    #[ORM\Column(length: 16)]
    public string $scope;
    #[ORM\Column(type: 'json')]
    public array $previousState;
    #[ORM\Column(type: 'json')]
    public array $newState;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $changedAt;
}
