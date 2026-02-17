<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

class UserProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, ['label' => 'Vorname'])
            ->add('lastname', TextType::class, ['label' => 'Nachname'])
            ->add('email', EmailType::class, ['label' => 'E-Mail-Adresse', 'required' => false])
            ->add('examinerId', TextType::class, [
                'label' => 'Prüfernummer', 
                'required' => false,
                'help' => 'Ihre offizielle Nummer für Urkunden.'
            ])
            // Passwort ändern (optional, mapped => false bedeutet, es ist nicht direkt in der Entity)
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => false,
                'first_options'  => ['label' => 'Neues Passwort (optional)', 'help' => 'Leer lassen, um es zu behalten.'],
                'second_options' => ['label' => 'Passwort wiederholen'],
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'constraints' => [
                    new Length(['min' => 6, 'minMessage' => 'Mindestens {{ limit }} Zeichen.'])
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}