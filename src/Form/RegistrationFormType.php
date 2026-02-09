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

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // --- INSTITUTION DATEN (Nicht direkt im User gespeichert, daher mapped => false) ---
        $builder
            ->add('instName', TextType::class, [
                'mapped' => false,
                'label' => 'Name der Institution (Schule/Verein)',
                'attr' => ['placeholder' => 'z.B. Goethe Gymnasium', 'class' => 'form-control mb-2']
            ])
            ->add('instType', ChoiceType::class, [
                'mapped' => false,
                'label' => 'Art der Institution',
                'choices'  => [
                    'Schule' => 'Schule',
                    'Verein' => 'Verein',
                    'Organisation' => 'Organisation',
                    'Einheit' => 'Einheit',
                ],
                'attr' => ['class' => 'form-select mb-2']
            ])
            ->add('contactPerson', TextType::class, [
                'mapped' => false,
                'label' => 'Verantwortliche Person',
                'attr' => ['placeholder' => 'Vorname Nachname', 'class' => 'form-control mb-3']
            ])
            ->add('instZip', TextType::class, [
                'mapped' => false,
                'label' => 'PLZ',
                'attr' => ['class' => 'form-control']
            ])
            ->add('instCity', TextType::class, [
                'mapped' => false,
                'label' => 'Ort',
                'attr' => ['class' => 'form-control']
            ])
            ->add('instStreet', TextType::class, [
                'mapped' => false,
                'label' => 'Straße & Hausnr.',
                'attr' => ['class' => 'form-control mb-4']
            ]);

        // --- USER DATEN ---
        $builder
            ->add('username', TextType::class, [
                'label' => 'Benutzername (für Login)',
                'attr' => ['class' => 'form-control mb-2']
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-Mail Adresse',
                'attr' => ['class' => 'form-control mb-2']
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Passwort',
                'attr' => ['autocomplete' => 'new-password', 'class' => 'form-control mb-3'],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte ein Passwort eingeben']),
                    new Length(['min' => 6, 'minMessage' => 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}