<?php

declare(strict_types=1);

namespace VerifiedHumanApproval\Demo\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class ApprovalPolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $modes = ['Issue alerts directly' => 'direct', 'Issue alerts only with verified human approval' => 'approval', 'Cannot issue alerts' => 'denied'];
        $builder->add('manager', ChoiceType::class, ['label' => 'Emergency manager', 'choices' => $modes])
            ->add('staff', ChoiceType::class, ['label' => 'County staff', 'choices' => $modes])
            ->add('statement', TextareaType::class, ['label' => 'Required approval statement', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 2000)]])
            ->add('version', HiddenType::class, ['constraints' => [new Assert\Regex('/^[0-9]+$/D')]]);
    }
}
