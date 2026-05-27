<?php

declare(strict_types=1);

namespace Beluga\Domain\Inhabil;

interface InhabilService
{
    /**
     * Previsualiza o aplica un día inhábil.
     * Si soloPreview=true devuelve el impacto sin persistir nada.
     * Si soloPreview=false aplica el inhábil y genera reposiciones (ventana 14 días).
     */
    public function declarar(
        string  $fecha,
        string  $motivo,
        ?string $sucursalId,
        string  $usuarioId,
        bool    $soloPreview = false
    ): array;

    public function listar(): array;
}
