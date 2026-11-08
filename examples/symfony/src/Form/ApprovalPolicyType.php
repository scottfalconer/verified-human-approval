<?php

declare(strict_types=1);

namespace ExactApproval\Demo\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class ApprovalPolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('required', CheckboxType::class, ['label' => 'Require exact approval for publishing', 'required' => false])
            ->add('statement', TextareaType::class, ['label' => 'Required approval statement', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 2000)]])
            ->add('version', HiddenType::class, ['constraints' => [new Assert\Regex('/^[0-9]+$/D')]]);
    }
}
