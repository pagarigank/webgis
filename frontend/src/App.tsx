import React from 'react';
import { Routes, Route, Link } from 'react-router-dom';
import HealthView from './pages/HealthView';

const Layout = ({ children }: { children: React.ReactNode }) => (
  <div>
    <header style={{ padding: '1rem', background: '#f0f0f0', borderBottom: '1px solid #ccc' }}>
      <nav>
        <Link to="/" style={{ marginRight: '1rem' }}>Home</Link>
        <Link to="/status">System Status</Link>
      </nav>
    </header>
    <main>
      {children}
    </main>
  </div>
);

const Home = () => (
  <div style={{ padding: '2rem' }}>
    <h1>Philippine Parcel & Multi-User GIS Web Application</h1>
    <p>Frontend bootstrap complete.</p>
  </div>
);

function App() {
  return (
    <Layout>
      <Routes>
        <Route path="/" element={<Home />} />
        <Route path="/status" element={<HealthView />} />
      </Routes>
    </Layout>
  );
}

export default App;
