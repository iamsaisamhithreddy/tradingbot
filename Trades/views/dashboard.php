<div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200">
    <form method="POST" class="flex flex-col md:flex-row gap-4 items-end">
        <input type="hidden" name="action" value="filter">
        <div class="w-full md:w-1/5">
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Start Date</label>
            <input type="date" name="start_date" value="<?= $f_start ?>" class="w-full border-slate-200 rounded-lg shadow-sm border p-2 focus:ring-blue-500 text-sm">
        </div>
        <div class="w-full md:w-1/5">
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">End Date</label>
            <input type="date" name="end_date" value="<?= $f_end ?>" class="w-full border-slate-200 rounded-lg shadow-sm border p-2 focus:ring-blue-500 text-sm">
        </div>
        <div class="w-full md:w-1/5">
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Asset Pair</label>
            <select name="asset" class="w-full border-slate-200 rounded-lg shadow-sm border p-2 focus:ring-blue-500 text-sm bg-white">
                <option value="All">All Assets</option>
                <?php foreach (array_keys($unique_assets) as $ast): ?>
                    <option value="<?= $ast ?>" <?= $f_asset === $ast ? 'selected' : '' ?>><?= $ast ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="w-full md:w-1/5 flex gap-2">
            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 rounded-lg shadow-sm transition text-sm">Apply Filters</button>
            <button type="submit" onclick="this.form.action.value='clear_filters';" class="w-full bg-slate-200 text-slate-700 font-bold py-2 rounded-lg shadow-sm transition text-sm">Clear</button>
        </div>
    </form>
</div>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
    <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 relative overflow-hidden">
        <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Total Trades</div>
        <div class="text-3xl font-black text-slate-800 mt-2"><?= $total_trades ?></div>
        <div class="text-[11px] text-slate-400 mt-1 font-semibold uppercase">W: <?= $wins ?> | L: <?= $losses ?> | T: <?= $ties ?></div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 relative overflow-hidden">
        <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Win Rate</div>
        <div class="text-3xl font-black text-blue-600 mt-2"><?= round($win_rate * 100, 2) ?>%</div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 relative overflow-hidden">
        <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Net Profit</div>
        <div class="text-3xl font-black mt-2 <?= $net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">₹<?= number_format($net_profit, 2) ?></div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 relative overflow-hidden">
        <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Max Drawdown</div>
        <div class="text-3xl font-black text-rose-600 mt-2">-₹<?= number_format($max_drawdown, 2) ?></div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 relative overflow-hidden">
        <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Trade Expectancy</div>
        <div class="text-3xl font-black <?= $expectancy >= 0 ? 'text-emerald-600' : 'text-rose-600' ?> mt-2">₹<?= number_format($expectancy, 2) ?></div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 lg:col-span-3">
        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4 border-b border-slate-100 pb-2">Cumulative Equity Curve</h3>
        <div class="relative h-72 w-full"><canvas id="equityChart"></canvas></div>
    </div>
    <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 lg:col-span-1 flex flex-col justify-between">
        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-2 border-b border-slate-100 pb-2">Win / Loss</h3>
        <div class="relative h-48 w-full flex-grow"><canvas id="winLossChart"></canvas></div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mt-6">
    <form method="POST" action="">
        <input type="hidden" name="action" value="save_remarks">
        <div class="flex justify-between items-center p-4 border-b border-slate-200 bg-slate-50">
            <h2 class="text-md font-bold text-slate-800">Trade Psychology Log</h2>
            <div class="flex gap-4">
                <input type="text" id="tableSearch" onkeyup="filterTable()" placeholder="Search..." class="border-slate-300 rounded-md shadow-sm border p-2 text-sm">
                <button type="submit" class="bg-blue-600 text-white font-bold py-2 px-8 rounded shadow-sm text-sm">Save Remarks</button>
            </div>
        </div>
        
        <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
            <table class="min-w-full divide-y divide-slate-200" id="tradeTable">
                <thead class="bg-white sticky top-0 z-10 shadow-sm">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">Date & Time</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">Asset</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">Income / Risk</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">ROI / Result</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase w-1/3">Remarks</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-100">
                    <?php 
                    $display_data = array_reverse($filtered_data, true);
                    foreach ($display_data as $index => $row): ?>
                        <?php 
                            $amount = isset($row['Amount']) ? (float)$row['Amount'] : 0;
                            $income = isset($row['Income']) ? (float)$row['Income'] : 0;
                            $is_win = $income > $amount;
                            $is_loss = $income < $amount;
                            
                            $roi_val = $amount > 0 ? (($income - $amount) / $amount) * 100 : 0;
                            $roi_badge_color = $roi_val > 0 ? 'text-emerald-700 bg-emerald-100' : ($roi_val < 0 ? 'text-rose-700 bg-rose-100' : 'text-slate-700 bg-slate-100');
                        ?>
                        <tr class="hover:bg-slate-50 transition border-l-4 <?= $is_win ? 'border-l-emerald-500' : ($is_loss ? 'border-l-rose-500' : 'border-l-transparent') ?>">
                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                <?= date('M j, Y', strtotime($row['Open time'])) ?><br>
                                <span class="text-xs text-slate-500"><?= date('h:i:s A', strtotime($row['Open time'])) ?></span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm font-bold text-slate-800"><?= $row['Info'] ?></div>
                                <?php foreach($row['Tags'] as $tag): ?>
                                    <span class="inline-block mt-1 px-1.5 py-0.5 text-[10px] font-bold rounded border bg-purple-100 text-purple-800 border-purple-200"><?= $tag ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-600 font-medium">
                                Payout: ₹<?= number_format($income, 2) ?><br>
                                <span class="text-xs text-slate-400">Risk: ₹<?= number_format($amount, 2) ?></span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="px-2 py-0.5 inline-flex text-xs font-bold rounded-md <?= $roi_badge_color ?>"><?= ($roi_val > 0 ? '+' : '') . round($roi_val, 2) ?>%</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-500">
                                <input type="text" name="remarks[<?= $index ?>]" value="<?= htmlspecialchars($row['Remarks']) ?>" class="block w-full rounded border-slate-300 bg-slate-50 focus:bg-white p-2 border" placeholder="Journal thoughts...">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>