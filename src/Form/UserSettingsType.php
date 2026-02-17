<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('theme', ChoiceType::class, [
                'label' => 'Design-Modus',
                'choices' => [
                    'Helles Design (Light)' => 'light',
                    'Dunkles Design (Dark)' => 'dark',
                    'Systemstandard' => 'auto',
                ],
                'expanded' => true, // Radio Buttons
                'attr' => ['class' => 'd-flex gap-3']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}