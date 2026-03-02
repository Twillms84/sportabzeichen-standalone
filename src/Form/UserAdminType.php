<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints\Email;

class UserAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, [
                'label' => 'Vorname',
                'attr' => ['class' => 'form-control']
            ])
            ->add('lastname', TextType::class, [
                'label' => 'Nachname',
                'attr' => ['class' => 'form-control']
            ])
            -->add('email', EmailType::class, [
                'label' => 'E-Mail / Login',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte eine E-Mail-Adresse angeben']),
                    new Email(['message' => 'Keine gültige E-Mail-Adresse']),
                ]
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Berechtigungs-Stufe',
                'choices'  => [
                    'PrüferIn' => 'ROLE_EXAMINER',
                    'AdministratorIn' => 'ROLE_ADMIN',
                ],
                'multiple' => true, // Symfony Roles sind immer ein Array
                'expanded' => false, // false = Dropdown, true = Checkboxes
                'attr' => ['class' => 'form-select']
            ])
            ->add('examinerId', TextType::class, [
                'label' => 'Prüfer-Nummer (optional)',
                'required' => false,
                'attr' => ['class' => 'form-control']
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => $options['is_new'] ? 'Passwort vergeben' : 'Passwort ändern (leer lassen für keine Änderung)',
                'mapped' => false,
                'required' => $options['is_new'],
                'attr' => ['class' => 'form-control', 'autocomplete' => 'new-password'],
                'constraints' => $options['is_new'] ? [
                    new NotBlank(['message' => 'Bitte ein Passwort eingeben']),
                    new Length(['min' => 6, 'max' => 4096]),
                ] : [],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => false, // Standardwert für Edit
        ]);
    }
}