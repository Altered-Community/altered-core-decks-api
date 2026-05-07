<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $keycloakId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $username = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isAdmin = false;

    #[ORM\OneToMany(targetEntity: Deck::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $decks;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->decks     = new ArrayCollection();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getKeycloakId(): string { return $this->keycloakId; }
    public function setKeycloakId(string $keycloakId): self { $this->keycloakId = $keycloakId; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $username): self { $this->username = $username; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    public function getDecks(): Collection { return $this->decks; }

    public function isAdmin(): bool { return $this->isAdmin; }
    public function setIsAdmin(bool $isAdmin): self { $this->isAdmin = $isAdmin; return $this; }

    // UserInterface
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if ($this->isAdmin) {
            $roles[] = 'ROLE_ADMIN';
        }
        return $roles;
    }
    public function eraseCredentials(): void {}
    public function getUserIdentifier(): string { return $this->keycloakId; }
}
