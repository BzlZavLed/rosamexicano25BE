<?php

namespace App\Console\Commands;

use App\Support\CashboxAutoCloser;
use Illuminate\Console\Command;

class AutoCloseCashbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cash:auto-close';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cierra automáticamente la caja del día si no se ha cerrado manualmente.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $result = CashboxAutoCloser::closePending();

        if ($result['count'] === 0) {
            $this->info('No hay cajas pendientes de cierre.');
            return Command::SUCCESS;
        }

        $this->info(sprintf(
            'Cerradas automáticamente %d cajas en las fechas: %s',
            $result['count'],
            !empty($result['dates']) ? implode(', ', $result['dates']) : 'sin fecha registrada'
        ));

        return Command::SUCCESS;
    }
}
