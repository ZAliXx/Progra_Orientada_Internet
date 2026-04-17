import { BrowserRouter as Router, Routes, Route } from "react-router-dom"

import Dashboard from "./pages/Dashboard"
import Call from "./pages/Call"

function App() {
return (
    <Router>
      <Routes>
        {/* Ruta principal */}
        <Route path="/" element={<Dashboard />} />

        <Route path="/chat" element={<Dashboard />} />
        <Route path="/call" element={<Call />} />
      </Routes>
    </Router>
  )
}

export default App