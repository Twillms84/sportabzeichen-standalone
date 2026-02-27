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
use Symfony\Component\Validator\Constraints\Regex;

class UserProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isExaminer = $options['is_examiner'];

        $builder
            ->add('firstname', TextType::class, ['label' => 'Vorname'])
            ->add('lastname', TextType::class, ['label' => 'Nachname'])
            ->add('email', EmailType::class, ['label' => 'E-Mail-Adresse', 'required' => false]);

        // Prüfernummer und aktuelles Passwort NUR für Prüfer anzeigen
        if ($isExaminer) {
            $builder->add('examinerId', TextType::class, [
                'label' => 'Prüfernummer', 
                'required' => false,
                'help' => 'Ihre offizielle Nummer für Urkunden.'
            ]);

            $builder->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Aktuelles Passwort',
                'help' => 'Nur ausfüllen, wenn Sie das Passwort ändern möchten.',
                'attr' => ['autocomplete' => 'current-password'],
            ]);
        }

        // Neues Passwort (dürfen alle setzen)
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'required' => false,
            'first_options'  => [
                'label' => 'Neues Passwort',
                'help' => $isExaminer ? '' : 'Setze dir ein eigenes Passwort, um dich künftig ohne QR-Code mit deiner E-Mail-Adresse einzuloggen.'
            ],
            'second_options' => ['label' => 'Neues Passwort wiederholen'],
            'invalid_message' => 'Die Passwörter stimmen nicht überein.',
            'constraints' => [
                new Length([
                    'min' => 8,
                    'minMessage' => 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.',
                    'max' => 4096,
                ]),
                new Regex([
                    'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/',
                    'message' => 'Das Passwort muss sicher sein: Groß- und Kleinbuchstaben, Zahlen und Sonderzeichen.'
                ])
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            // Hier definieren wir unsere neue Option und setzen den Standard auf false
            'is_examiner' => false, 
        ]);
    }
}