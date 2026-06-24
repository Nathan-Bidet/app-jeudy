<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Prix carburant</title>
    <style>
@include('cotations.partials.pdf-styles')
    </style>
</head>
<body>

@include('cotations.partials.fuel-page', ['fuelGrid' => $fuelGrid, 'generatedAt' => $generatedAt])

</body>
</html>
