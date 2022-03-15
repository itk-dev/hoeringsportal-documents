<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Loggable\Loggable;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Yaml\Yaml;

/**
 * @ORM\Entity(repositoryClass="App\Repository\ArchiverRepository")
 * @Gedmo\Loggable()
 * @UniqueEntity("name")
 */
class Archiver implements Loggable, \JsonSerializable
{
    use TimestampableEntity;
    public const TYPE_SHAREFILE2EDOC = 'sharefile2edoc';
    public const TYPE_ERPO2SHAREFILE2EDOC = 'erpo2sharefile2edoc';
    public const TYPE_PDF_COMBINE = 'pdfcombine';
    public const TYPE_MIN_EJENDOM = 'minejendom';
    public const TYPE_HEARING_OVERVIEW = 'hearing overview';

    /**
     * @ORM\Id()
     * @ORM\Column(type="guid")
     * @ORM\GeneratedValue(strategy="UUID")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     * @Gedmo\Versioned()
     */
    private $name;

    /**
     * @ORM\Column(type="text")
     * @Gedmo\Versioned()
     */
    private $configuration;

    /**
     * @ORM\Column(type="boolean")
     * @Gedmo\Versioned()
     */
    private $enabled;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $lastRunAt;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $type;

    public function __toString()
    {
        return $this->name ?? self::class;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getConfiguration(): ?string
    {
        return $this->configuration;
    }

    public function setConfiguration(string $configuration): self
    {
        $this->configuration = $configuration;

        return $this;
    }

    public function isEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeInterface
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeInterface $lastRunAt): self
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }

    public function getConfigurationValue(string $key = null, $default = null)
    {
        $configuration = Yaml::parse($this->getConfiguration());

        if (null === $key) {
            return $configuration;
        }

        if (\array_key_exists($key, $configuration)) {
            return $configuration[$key];
        }

        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        return $propertyAccessor->getValue($configuration, $key) ?? $default;
    }

    public function getCreateCaseFile(): bool
    {
        $value = $this->getConfigurationValue('edoc');

        return isset($value['project_id']);
    }

    public function getCreateHearing(): bool
    {
        return $this->getCreateCaseFile();
    }

    /**
     * Get eDoc organization reference (id) from Deskpro department id.
     *
     * @param $id
     *
     * @return null|int
     */
    public function getEdocOrganizationReference($id)
    {
        $map = $this->getConfigurationValue('[edoc][organizations]') ?? [];

        return $map[$id] ?? $map['default'] ?? null;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function jsonSerialize()
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'configuration' => $this->getConfigurationValue(),
        ];
    }
}
